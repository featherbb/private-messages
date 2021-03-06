<?php

/**
 * Copyright (C) 2015-2016 FeatherBB
 * based on code by (C) 2008-2012 FluxBB
 * and Rickard Andersson (C) 2002-2008 PunBB
 * License: http://www.gnu.org/licenses/gpl.html GPL version 2 or higher
 */

namespace FeatherBB\Plugins;

use FeatherBB\Core\Database as DB;
use FeatherBB\Core\Error;
use FeatherBB\Core\Plugin as BasePlugin;
use FeatherBB\Middleware\LoggedIn;

class PrivateMessages extends BasePlugin
{

    public function run()
    {
        Container::get('hooks')->bind('admin.plugin.menu', [$this, 'getName']);
        Container::get('hooks')->bind('view.header.navlinks', [$this, 'addNavlink']);
        Container::get('hooks')->bind('model.print_posts.one', function ($cur_post) {
            $cur_post['user_contacts'][] = '<span class="email"><a href="'.Router::pathFor('Conversations.send', ['uid' => $cur_post['poster_id']]).'">PM</a></span>';
            return $cur_post;
        });

        Route::group('/conversations', function() {
            Route::map(['GET', 'POST'], '/inbox[/{inbox_id:[0-9]+}]', '\FeatherBB\Plugins\Controller\PrivateMessages:index')->setName('Conversations.home');
            Route::map(['GET', 'POST'], '/inbox/{inbox_id:[0-9]+}/page/{page:[0-9]+}', '\FeatherBB\Plugins\Controller\PrivateMessages:index')->setName('Conversations.home.page');
            Route::get('/thread/{tid:[0-9]+}', '\FeatherBB\Plugins\Controller\PrivateMessages:show')->setName('Conversations.show');
            Route::get('/thread/{tid:[0-9]+}/page/{page:[0-9]+}', '\FeatherBB\Plugins\Controller\PrivateMessages:show')->setName('Conversations.show.page');
            Route::map(['GET', 'POST'], '/send[/{uid:[0-9]+}]', '\FeatherBB\Plugins\Controller\PrivateMessages:send')->setName('Conversations.send');
            Route::map(['GET', 'POST'], '/reply/{tid:[0-9]+}', '\FeatherBB\Plugins\Controller\PrivateMessages:reply')->setName('Conversations.reply');
            Route::map(['GET', 'POST'], '/quote/{mid:[0-9]+}', '\FeatherBB\Plugins\Controller\PrivateMessages:reply')->setName('Conversations.quote');
            Route::map(['GET', 'POST'], '/options/blocked', '\FeatherBB\Plugins\Controller\PrivateMessages:blocked')->setName('Conversations.blocked');
            Route::map(['GET', 'POST'], '/options/folders', '\FeatherBB\Plugins\Controller\PrivateMessages:folders')->setName('Conversations.folders');
        })->add(new \FeatherBB\Plugins\Middleware\LoggedIn());

        View::addAsset('css', 'plugins/private-messages/src/style/private-messages.css');
    }

    public function addNavlink($navlinks)
    {
        translate('private_messages', 'private-messages');
        if (!User::get()->is_guest) {
            $nbUnread = Model\PrivateMessages::countUnread(User::get()->id);
            $count = ($nbUnread > 0) ? ' ('.$nbUnread.')' : '';
            $navlinks[] = '4 = <a href="'.Router::pathFor('Conversations.home').'">PMS'.$count.'</a>';
            if ($nbUnread > 0) {
                Container::get('hooks')->bind('header.toplist', function($toplists) {
                    $toplists[] = '<li class="reportlink"><span><strong><a href="'.Router::pathFor('Conversations.home', ['inbox_id' => 1]).'">'.__('Unread messages', 'private_messages').'</a></strong></span></li>';
                    return $toplists;
                });
            }
        }
        return $navlinks;
    }

    public function install()
    {
        translate('private_messages', 'private-messages', ForumSettings::get('o_default_lang'));

        $database_scheme = array(
            'pms_data' => "CREATE TABLE IF NOT EXISTS %t% (
                `conversation_id` int(10) unsigned NOT NULL,
                `user_id` int(10) unsigned NOT NULL DEFAULT '0',
                `deleted` tinyint(1) unsigned NOT NULL DEFAULT '0',
                `viewed` tinyint(1) unsigned NOT NULL DEFAULT '0',
                `folder_id` int(10) unsigned NOT NULL DEFAULT '2',
                PRIMARY KEY (`conversation_id`, `user_id`),
                KEY `folder_idx` (`folder_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8;",
            'pms_folders' => "CREATE TABLE IF NOT EXISTS %t% (
                `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
                `name` varchar(80) NOT NULL DEFAULT 'New Folder',
                `user_id` int(10) unsigned NOT NULL DEFAULT '0',
                PRIMARY KEY (`id`),
                KEY `user_idx` (`user_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8;",
            'pms_messages' => "CREATE TABLE IF NOT EXISTS %t% (
                `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
                `poster` varchar(200) NOT NULL DEFAULT '',
                `poster_id` int(10) unsigned NOT NULL DEFAULT '1',
                `poster_ip` varchar(39) DEFAULT NULL,
                `message` mediumtext,
                `hide_smilies` tinyint(1) NOT NULL DEFAULT '0',
                `sent` int(10) unsigned NOT NULL DEFAULT '0',
                `edited` int(10) unsigned DEFAULT NULL,
                `edited_by` varchar(200) DEFAULT NULL,
                `conversation_id` int(10) unsigned NOT NULL DEFAULT '0',
                PRIMARY KEY (`id`),
                KEY `conversation_idx` (`conversation_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8;",
            'pms_conversations' => "CREATE TABLE IF NOT EXISTS %t% (
                `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
                `poster` varchar(200) NOT NULL DEFAULT '',
                `poster_id` int(10) unsigned NOT NULL DEFAULT '0',
                `subject` varchar(255) NOT NULL DEFAULT '',
                `first_post_id` int(10) unsigned NOT NULL DEFAULT '0',
                `last_post_id` int(10) unsigned NOT NULL DEFAULT '0',
                `last_post` int(10) unsigned NOT NULL DEFAULT '0',
                `last_poster` varchar(200) DEFAULT NULL,
                `num_replies` mediumint(8) unsigned NOT NULL DEFAULT '0',
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8;",
            'pms_blocks' => "CREATE TABLE IF NOT EXISTS %t% (
                `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
                `user_id` int(10) NOT NULL DEFAULT '0',
                `block_id` int(10) NOT NULL DEFAULT '0',
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8;"
        );

        // Create tables
        $installer = new \FeatherBB\Model\Install();
        foreach ($database_scheme as $table => $sql) {
            $installer->create_table(ForumSettings::get('db_prefix').$table, $sql);
        }

        DB::for_table('groups')->raw_execute('ALTER TABLE '.ForumSettings::get('db_prefix').'groups ADD `g_pm_limit` smallint(3) NOT NULL DEFAULT \'0\'');
        DB::for_table('groups')->raw_execute('ALTER TABLE '.ForumSettings::get('db_prefix').'groups ADD `g_use_pm` tinyint(1) NOT NULL DEFAULT \'0\'');
        DB::for_table('groups')->raw_execute('ALTER TABLE '.ForumSettings::get('db_prefix').'groups ADD `g_pm_folder_limit` int(3) NOT NULL DEFAULT \'0\'');

        // Create default inboxes
        $folders = array(
            __('New', 'private_messages'),
            __('Inbox', 'private_messages'),
            __('Archived', 'private_messages')
        );

        foreach ($folders as $folder)
        {
            $insert = array(
                'name'    =>    $folder,
                'user_id'    =>    1,
            );
            $installer->add_data('pms_folders', $insert);
        }
    }

    public function remove()
    {
        $db = DB::get_db();
        $tables = ['pms_data', 'pms_folders', 'pms_messages', 'pms_conversations', 'pms_blocks'];
        foreach ($tables as $i)
        {
            $tableExists = DB::for_table($i)->raw_query('SHOW TABLES LIKE "'. ForumSettings::get('db_prefix').$i . '"')->find_one();
            if ($tableExists)
            {
                $db->exec('DROP TABLE '.ForumSettings::get('db_prefix').$i);
            }
        }
        $columns = ['g_pm_limit', 'g_use_pm', 'g_pm_folder_limit'];
        foreach ($columns as $i)
        {
            $columnExists = DB::for_table('groups')->raw_query('SHOW COLUMNS FROM '. ForumSettings::get('db_prefix') . 'groups LIKE \'' . $i .'\'')->find_one();
            if ($columnExists)
            {
                $db->exec('ALTER TABLE '.ForumSettings::get('db_prefix').'groups DROP COLUMN '.$i);
            }
        }
    }

}
