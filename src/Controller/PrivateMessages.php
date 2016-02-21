<?php

/**
 * Copyright (C) 2015-2016 FeatherBB
 * based on code by (C) 2008-2012 FluxBB
 * and Rickard Andersson (C) 2002-2008 PunBB
 * License: http://www.gnu.org/licenses/gpl.html GPL version 2 or higher
 */

namespace FeatherBB\Plugins\Controller;


use FeatherBB\Core\Database as DB;
use FeatherBB\Core\Error;
use FeatherBB\Core\Url;
use FeatherBB\Core\Utils;

class PrivateMessages
{
    protected $model, $crumbs, $inboxes;

    public function __construct()
    {
        $this->model = new \FeatherBB\Plugins\Model\PrivateMessages();
        load_textdomain('private_messages', dirname(dirname(__FILE__)).'/lang/'.Container::get('user')->language.'/private-messages.mo');
        load_textdomain('featherbb', ForumEnv::get('FEATHER_ROOT').'featherbb/lang/'.Container::get('user')->language.'/misc.mo');
        View::addTemplatesDirectory(dirname(dirname(__FILE__)).'/Views', 5)->setPageInfo(['active_page' => 'navextra1']);
        $this->crumbs =array(
            Router::pathFor('Conversations.home') => __('PMS', 'private_messages')
        );
    }

    public function index($req, $res, $args)
    {
        // Set default page to "Inbox" folder
        if (!isset($args['inbox_id'])) {
            $args['inbox_id'] = 2;
        }

        if (!isset($args['page'])) {
            $args['page'] = 1;
        }

        $uid = intval(Container::get('user')->id);

        if ($action = Input::post('action')) {
            switch ($action) {
                case 'move':
                    $this->move();
                    break;
                case 'delete':
                    $this->delete();
                    break;
                case 'read':
                    $this->markRead();
                    break;
                case 'unread':
                    $this->markRead(0);
                    break;
                default:
                    return Router::redirect(Router::pathFor('Conversations.home', ['inbox_id' => Input::post('inbox_id')]));
                    break;
            }
        }

        if ($this->inboxes = $this->model->getInboxes(Container::get('user')->id)) {
            if (!in_array($args['inbox_id'], array_keys($this->inboxes))) {
                throw new Error(__('Wrong folder owner', 'private_messages'), 403);
            }
        }
        // Page data
        $num_pages = ceil($this->inboxes[$args['inbox_id']]['nb_msg'] / Container::get('user')['disp_topics']);
        $p = (!isset($args['page']) || $args['page'] <= 1 || $args['page'] > $num_pages) ? 1 : intval($args['page']);
        $start_from = Container::get('user')['disp_topics'] * ($p - 1);
        $paging_links = Url::paginate($num_pages, $p, Router::pathFor('Conversations.home', ['id' => $args['inbox_id']]).'/#');

        // Make breadcrumbs
        $this->crumbs[Router::pathFor('Conversations.home', ['inbox_id' => $args['inbox_id']])] = $this->inboxes[$args['inbox_id']]['name'];
        $this->crumbs[] = __('My conversations', 'private_messages');
        Utils::generateBreadcrumbs($this->crumbs, array(
            'link' => Router::pathFor('Conversations.send'),
            'text' => __('Send', 'private_messages')
        ));

        $this->generateMenu($this->inboxes[$args['inbox_id']]['name']);
        View::addAsset('js', 'style/imports/common.js', array('type' => 'text/javascript'));
        View::setPageInfo(array(
                'title' => array(Utils::escape(ForumSettings::get('o_board_title')), __('PMS', 'private_messages'), $this->inboxes[$args['inbox_id']]['name']),
                'admin_console' => true,
                'inboxes' => $this->inboxes,
                'current_inbox_id' => $args['inbox_id'],
                'paging_links' => $paging_links,
                'rightLink' => ['link' => Router::pathFor('Conversations.send'), 'text' => __('Send', 'private_messages')],
                'conversations' => $this->model->getConversations($args['inbox_id'], $uid, Container::get('user')['disp_topics'], $start_from)
            )
        )
        ->addTemplate('index.php')->display();
    }


    public function info($req, $res, $args)
    {
        // Update permissions
        if (Request::isPost()) {
            return $this->model->update_permissions();
        }
        return View::setPageInfo(array(
                'title' => array(Utils::escape(ForumSettings::get('o_board_title')), __('PMS', 'private_messages')),
                'groups' => $this->model->fetch_groups(),
                'admin_console' => true,
            )
        )
        ->addTemplate('info.php')->display();
    }

    public function delete($req, $res, $args)
    {
        if (!Input::post('topics'))
            throw new Error(__('No conv selected', 'private_messages'), 403);

        $topics = Input::post('topics') && is_array(Input::post('topics')) ? array_map('intval', Input::post('topics')) : array_map('intval', explode(',', Input::post('topics')));

        if (empty($topics))
            throw new Error(__('No conv selected', 'private_messages'), 403);

        if ( Input::post('delete_comply') )
        {
            $uid = intval(Container::get('user')->id);
            $this->model->delete($topics, $uid);

            return Router::redirect(Router::pathFor('Conversations.home'), __('Conversations deleted', 'private_messages'));
        }
        else
        {
            // Display confirm delete form
            View::setPageInfo(array(
                    'title' => array(Utils::escape(ForumSettings::get('o_board_title')), __('PMS', 'private_messages')),
                    'topics' => $topics,
                )
            )
            ->addTemplate('delete.php')->display();
        }
        die();
    }

    public function move($req, $res, $args)
    {
        if (!Input::post('topics'))
            throw new Error(__('No conv selected', 'private_messages'), 403);

        $topics = Input::post('topics') && is_array(Input::post('topics')) ? array_map('intval', Input::post('topics')) : array_map('intval', explode(',', Input::post('topics')));

        if (empty($topics))
            throw new Error(__('No conv selected', 'private_messages'), 403);

        $uid = intval(Container::get('user')->id);

        if ( Input::post('move_comply') )
        {
            $move_to = Input::post('move_to') ? intval(Input::post('move_to')) : 2;

            if ( $this->model->move($topics, $move_to, $uid) ) {
                return Router::redirect(Router::pathFor('Conversations.home', ['inbox_id' => $move_to]), __('Conversations moved', 'private_messages'));
            } else {
                throw new Error(__('Error Move', 'private_messages'), 403);
            }
        }

        // Display move form
        if ($inboxes = $this->model->getUserFolders($uid)) {
            View::setPageInfo(array(
                    'title' => array(Utils::escape(ForumSettings::get('o_board_title')), __('PMS', 'private_messages')),
                    'topics' => $topics,
                    'inboxes' => $inboxes,
                )
            )
            ->addTemplate('move.php')->display();
        } else {
            throw new Error('No inboxes', 404);
        }

        die();
    }

    public function markRead($req, $res, $args)
    {
        $read = false;

        if (isset($args['read'])) {
            $read = true;
        }

        $viewed = ($read == true) ? '1' : '0';

        if (!Input::post('topics'))
            throw new Error(__('No conv selected', 'private_messages'), 403);

        $topics = Input::post('topics') && is_array(Input::post('topics')) ? array_map('intval', Input::post('topics')) : array_map('intval', explode(',', Input::post('topics')));

        if (empty($topics))
            throw new Error(__('No conv selected', 'private_messages'), 403);

        $this->model->updateConversation($topics, Container::get('user')->id, ['viewed' => $viewed]);

        return Router::redirect(Router::pathFor('Conversations.home', ['inbox_id' => Input::post('inbox_id')]));
    }

    public function send($req, $res, $args)
    {
        if (!isset($args['uid'])) {
            $args['uid'] = null;
        }

        if (!isset($args['tid'])) {
            $args['tid'] = null;
        }

        if (Request::isPost()) {
            // First raw validation
            $data = array_merge(array(
                'username' => null,
                'subject' => null,
                'message' => null,
                'smilies' => 0,
                'preview' => null,
            ), Request::getParsedBody());
            $data = array_map(array('FeatherBB\Core\Utils', 'trim'), $data);

            $conv = false;

            if (!is_null($args['tid'])) {
                if ($args['tid'] < 1) {
                    throw new Error('Wrong conversation ID', 400);
                }
                if (!$conv = $this->model->getConversation($args['tid'], Container::get('user')->id)) {
                    throw new Error('Unknown conversation ID', 400);
                }
            }

            // Preview message
            if (Input::post('preview')) {
                // Make breadcrumbs
                $this->crumbs[] = __('Reply', 'private_messages');
                $this->crumbs[] = __('Preview');
                Utils::generateBreadcrumbs($this->crumbs);

                Container::get('hooks')->fire('conversationsPlugin.send.preview');
                $msg = Container::get('parser')->parse_message($data['req_message'], $data['smilies']);
                View::setPageInfo(array(
                    'parsed_message' => $msg,
                    'username' => Utils::escape($data['username']),
                    'subject' => Utils::escape($data['subject']),
                    'message' => Utils::escape($data['req_message'])
                ))->addTemplate('send.php')->display();
            } else {
                // Prevent flood
                if (!is_null($data['preview']) && Container::get('user')['last_post'] != '' && (Container::get('now') - Container::get('user')['last_post']) < Container::get('prefs')->get(Container::get('user'), 'post.min_interval')) {
                    throw new Error(sprintf(__('Flood start'), Container::get('prefs')->get(Container::get('user'), 'post.min_interval'), Container::get('prefs')->get(Container::get('user'), 'post.min_interval') - (Container::get('now') - Container::get('user')['last_post'])), 429);
                }

                if (!$conv) {
                    // Validate username / TODO : allow multiple usernames
                    if (!$user = $this->model->isAllowed($data['username'])) {
                        throw new Error('You can\'t send an PM to '.($data['username'] ? $data['username'] : 'nobody'), 400);
                    }

                    // Avoid self messages
                    if ($user->id == Container::get('user')->id) {
                        throw new Error('No self message', 403);
                    }

                    // Validate subject
                    if (ForumSettings::get('o_censoring') == '1')
                    $data['subject'] = Utils::trim(Utils::censor($data['subject']));
                    if (empty($data['subject'])) {
                        throw new Error('No subject or censored subject', 400);
                    } else if (Utils::strlen($data['subject']) > 70) {
                        throw new Error('Too long subject', 400);
                    } else if (ForumSettings::get('p_subject_all_caps')['p_subject_all_caps'] == '0' && Utils::is_all_uppercase($data['subject']) && !Container::get('user')->is_admmod) {
                        throw new Error('All caps subject forbidden', 400);
                    }
                }

                // TODO : inbox full

                // Validate message
                if (ForumSettings::get('o_censoring') == '1')
                $data['req_message'] = Utils::trim(Utils::censor($data['req_message']));
                if (empty($data['req_message'])) {
                    throw new Error('No message or censored message', 400);
                } else if (Utils::strlen($data['req_message']) > ForumEnv::get('FEATHER_MAX_POSTSIZE')) {
                    throw new Error('Too long message', 400);
                } else if (ForumSettings::get('p_subject_all_caps')['p_subject_all_caps'] == '0' && Utils::is_all_uppercase($data['subject']) && !Container::get('user')->is_admmod) {
                    throw new Error('All caps message forbidden', 400);
                }

                // Send ... TODO : when perms will be ready
                // Check if the receiver has the PM enabled
                // Check if he has reached his max limit of PM
                // Block feature ?

                if (!$conv) {
                    $conv_data = array(
                        'subject'    =>    $data['subject'],
                        'poster'    =>    Container::get('user')->username,
                        'poster_id'    =>    Container::get('user')->id,
                        'num_replies'    =>    0,
                        'last_post'    =>    Container::get('now'),
                        'last_poster'    =>    Container::get('user')->username);
                    $args['tid'] = $this->model->addConversation($conv_data);
                }
                if ($args['tid']) {
                    $msg_data = array(
                        'poster'    =>    Container::get('user')->username,
                        'poster_id'    =>    Container::get('user')->id,
                        'poster_ip'    =>    Utils::getIp(),
                        'message'    =>    $data['req_message'],
                        'hide_smilies'    =>    $data['smilies'],
                        'sent'    =>    Container::get('now'),
                    );
                    if ($conv) {
                        // Reply to an existing conversation
                        if ($msg_id = $this->model->addMessage($msg_data, $args['tid'])) {
                            return Router::redirect(Router::pathFor('Conversations.home'), sprintf(__('Reply success', 'private_messages'), $conv->subject));
                        }
                    } else {
                        // Add message in conversation + add receiver (create new conversation)
                        if ($msg_id = $this->model->addMessage($msg_data, $args['tid'], array($user->id, Container::get('user')->id))) {
                            return Router::redirect(Router::pathFor('Conversations.home'), sprintf(__('Send success', 'private_messages'), $user->username));
                        }
                    }
                } else {
                    throw new Error('Unable to create conversation');
                }
            }
        } else {
            Container::get('hooks')->fire('conversationsPlugin.send.display');
            // New conversation
            if (!is_null($args['uid'])) {
                if ($args['uid'] < 2) {
                    throw new Error('Wrong user ID', 400);
                }
                if ($user = $this->model->getUserByID($args['uid'])) {
                    View::setPageInfo(array('username' => Utils::escape($user->username)));
                } else {
                    throw new Error('Unable to find user', 400);
                }
            }
            // Reply
            if (!is_null($args['tid'])) {
                if ($args['tid'] < 1) {
                    throw new Error('Wrong conversation ID', 400);
                }
                if ($conv = $this->model->getConversation($args['tid'], Container::get('user')->id)) {
                    $inbox = DB::for_table('pms_folders')->find_one($conv->folder_id);
                    $this->crumbs[Router::pathFor('Conversations.home', ['inbox_id' => $inbox['id']])] = $inbox['name'];
                    $this->crumbs[] = __('Reply', 'private_messages');
                    $this->crumbs[] = $conv['subject'];
                    Utils::generateBreadcrumbs($this->crumbs);
                    return View::setPageInfo(array(
                        'current_inbox' => $inbox,
                        'conv' => $conv,
                        'msg_data' => $this->model->getMessagesFromConversation($args['tid'], Container::get('user')->id, 5)
                    ))->addTemplate('reply.php')->display();
                } else {
                    throw new Error('Unknown conversation ID', 400);
                }
            }
            $this->crumbs[] = __('Send', 'private_messages');
            if(isset($user)) $this->crumbs[] = $user->username;
            Utils::generateBreadcrumbs($this->crumbs);
            View::addTemplate('send.php')->display();
        }
    }

    public function reply($req, $res, $args)
    {
        return $this->send($req, $res, $args);
    }

    public function show($req, $res, $args)
    {
        if (!isset($args['tid'])) {
            $args['tid'] = null;
        }

        if (!isset($args['page'])) {
            $args['page'] = null;
        }

        // First checks
        if ($args['tid'] < 1) {
            throw new Error('Wrong conversation ID', 400);
        }
        if (!$conv = $this->model->getConversation($args['tid'], Container::get('user')->id)) {
            throw new Error('Unknown conversation ID', 404);
        } else if ($this->model->isDeleted($args['tid'], Container::get('user')->id)) {
            throw new Error('The conversation has been deleted', 404);
        }

        // Set conversation as viewed
        if ($conv['viewed'] == 0) {
            if (!$this->model->setViewed($args['tid'], Container::get('user')->id)) {
                throw new Error('Unable to set conversation as viewed', 500);
            }
        }

        $num_pages = ceil($conv['num_replies'] / Container::get('user')['disp_topics']);
        $p = (!is_null($args['page']) || $args['page'] <= 1 || $args['page'] > $num_pages) ? 1 : intval($args['page']);
        $start_from = Container::get('user')['disp_topics'] * ($p - 1);
        $paging_links = Url::paginate($num_pages, $p, Router::pathFor('Conversations.show', ['tid' => $args['tid']]).'/#');

        $this->inboxes = $this->model->getInboxes(Container::get('user')->id);

        $this->crumbs[Router::pathFor('Conversations.home', ['inbox_id' => $conv['folder_id']])] = $this->inboxes[$conv['folder_id']]['name'];
        $this->crumbs[] = __('My conversations', 'private_messages');
        $this->crumbs[] = $conv['subject'];
        Utils::generateBreadcrumbs($this->crumbs, array(
            'link' => Router::pathFor('Conversations.reply', ['tid' => $conv['id']]),
            'text' => __('Reply', 'private_messages')
        ));
        $this->generateMenu($this->inboxes[$conv['folder_id']]['name']);
        View::setPageInfo(array(
                'title' => array(Utils::escape(ForumSettings::get('o_board_title')), __('PMS', 'private_messages'), $this->model->getUserFolders(Container::get('user')->id)[$conv['folder_id']]['name'], Utils::escape($conv['subject'])),
                'admin_console' => true,
                'paging_links' => $paging_links,
                'start_from' => $start_from,
                'cur_conv' => $conv,
                'rightLink' => ['link' => Router::pathFor('Conversations.reply', ['tid' => $conv['id']]), 'text' => __('Reply', 'private_messages')],
                'messages' => $this->model->getMessages($conv['id'], Container::get('user')['disp_topics'], $start_from)
            )
        )
        ->addTemplate('show.php')->display();
    }

    public function blocked($req, $res, $args)
    {
        $errors = array();

        $username = Input::post('req_username') ? Utils::trim(Utils::escape(Input::post('req_username'))) : '';
        if (Input::post('add_block'))
        {
            if ($username == Container::get('user')->username)
                $errors[] = __('No block self', 'private_messages');

            if (!($user_infos = $this->model->getUserByName($username)) || $username == __('Guest'))
                $errors[] = sprintf(__('No user name message', 'private_messages'), Utils::escape($username));

            if (empty($errors))
            {
                if ($user_infos->group_id == ForumEnv::get('FEATHER_ADMIN'))
                    $errors[] = sprintf(__('User is admin', 'private_messages'), Utils::escape($username));
                elseif ($user_infos->group_id == ForumEnv::get('FEATHER_MOD'))
                    $errors[] = sprintf(__('User is mod', 'private_messages'), Utils::escape($username));

                if ($this->model->checkBlock(Container::get('user')->id, $user_infos->id))
                    $errors[] = sprintf(__('Already blocked', 'private_messages'), Utils::escape($username));
            }

            if (empty($errors))
            {
                $insert = array(
                    'user_id'    =>    Container::get('user')->id,
                    'block_id'    =>    $user_infos->id,
                );

                $this->model->addBlock($insert);
                return Router::redirect(Router::pathFor('Conversations.blocked'), __('Block added', 'private_messages'));
            }
        }
        else if (Input::post('remove_block'))
        {
            $id = intval(key(Input::post('remove_block')));
            // Before we do anything, check we blocked this user
            if (!$this->model->checkBlock(intval(Container::get('user')->id), $id))
                throw new Error(__('No permission'), 403);

            $this->model->removeBlock(intval(Container::get('user')->id), $id);
            return Router::redirect(Router::pathFor('Conversations.blocked'), __('Block removed', 'private_messages'));
        }

        Utils::generateBreadcrumbs(array(
            Router::pathFor('Conversations.home') => __('PMS', 'private_messages'),
            __('Options'),
            __('Blocked Users', 'private_messages')
        ));

        $this->generateMenu('blocked');
        View::setPageInfo(array(
                'title' => array(Utils::escape(ForumSettings::get('o_board_title')), __('PMS', 'private_messages'), __('Blocked Users', 'private_messages')),
                'admin_console' => true,
                'errors' => $errors,
                'username' => $username,
                'required_fields' => array('req_username' => __('Add block', 'private_messages')),
                'blocks' => $this->model->getBlocked(Container::get('user')->id),
            )
        )
        ->addTemplate('blocked.php')->display();
    }

    public function folders($req, $res, $args)
    {
        $errors = array();

        if (Input::post('add_folder'))
        {
            $folder = Input::post('req_folder') ? Utils::trim(Utils::escape(Input::post('req_folder'))) : '';

            if ($folder == '')
                $errors[] = __('No folder name', 'private_messages');
            else if (Utils::strlen($folder) < 4)
                $errors[] = __('Folder too short', 'private_messages');
            else if (Utils::strlen($folder) > 30)
                $errors[] = __('Folder too long', 'private_messages');
            else if (ForumSettings::get('o_censoring') == '1' && Utils::censor($folder) == '')
                $errors[] = __('No folder after censoring', 'private_messages');

            // TODO: Check perms when ready
            // $data = array(
            //     ':uid'    =>    $panther_user['id'],
            // );
            //
            // if ($panther_user['g_pm_folder_limit'] != 0)
            // {
            //     $ps = $db->select('folders', 'COUNT(id)', $data, 'user_id=:uid');
            //     $num_folders = $ps->fetchColumn();
            //
            //     if ($num_folders >= $panther_user['g_pm_folder_limit'])
            //         $errors[] = sprintf($lang_pm['Folder limit'], $panther_user['g_pm_folder_limit']);
            // }

            if (empty($errors))
            {
                $insert = array(
                    'user_id'    =>    Container::get('user')->id,
                    'name'    =>    $folder
                );

                $this->model->addFolder($insert);
                return Router::redirect(Router::pathFor('Conversations.folders'), __('Folder added', 'private_messages'));
            }
        }
        else if (Input::post('update_folder'))
        {
            $id = intval(key(Input::post('update_folder')));
            var_dump($id);

            $errors = array();
            $folder = Utils::trim(Input::post('folder')[$id]);

            if ($folder == '')
                $errors[] = __('No folder name', 'private_messages');
            else if (Utils::strlen($folder) < 4)
                $errors[] = __('Folder too short', 'private_messages');
            else if (Utils::strlen($folder) > 30)
                $errors[] = __('Folder too long', 'private_messages');
            else if (ForumSettings::get('o_censoring') == '1' && Utils::censor($folder) == '')
                $errors[] = __('No folder after censoring', 'private_messages');

            if (empty($errors))
            {
                $update = array(
                    'name'    =>    $folder,
                );

                if ($this->model->updateFolder(Container::get('user')->id, $id, $update))
                    return Router::redirect(Router::pathFor('Conversations.folders'), __('Folder updated', 'private_messages'));
                else
                    throw new Error(__('Error'), 403);
            }
        }
        else if (Input::post('remove_folder'))
        {
            $id = intval(key(Input::post('remove_folder')));
            // Before we do anything, check we blocked this user
            if (!$this->model->checkFolderOwner($id, intval(Container::get('user')->id)))
                throw new Error(__('No permission'), 403);

            if ($this->model->removeFolder(Container::get('user')->id, $id))
                return Router::redirect(Router::pathFor('Conversations.folders'), __('Folder removed', 'private_messages'));
            else
                throw new Error(__('Error'), 403);
        }

        Utils::generateBreadcrumbs(array(
            Router::pathFor('Conversations.home') => __('PMS', 'private_messages'),
            __('Options'),
            __('My Folders', 'private_messages')
        ));

        $this->generateMenu('folders');
        View::setPageInfo(array(
                'title' => array(Utils::escape(ForumSettings::get('o_board_title')), __('PMS', 'private_messages'), __('Blocked Users', 'private_messages')),
                'admin_console' => true,
                'errors' => $errors
            )
        )
        ->addTemplate('folders.php')->display();
    }

    public function generateMenu($page = '')
    {
        if (!isset($this->inboxes))
            $this->inboxes = $this->model->getInboxes(Container::get('user')->id);

        View::setPageInfo(array(
            'page'    =>    $page,
            'inboxes'    =>    $this->inboxes,
            ), 1
        )->addTemplate('menu.php');
        return $this->inboxes;
    }

}
