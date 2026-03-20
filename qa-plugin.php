<?php

if (!defined('QA_VERSION')) {
    header('Location: ../../');
    exit;
}

// Register admin module
qa_register_plugin_module('process', 'SmpAdmin.php', 'SmpAdmin', 'Social Media Poster Admin');

// Register event handlers
qa_register_plugin_module('event', 'SmpEventQuestion.php', 'SmpEventQuestion', 'SMP Event Question');
qa_register_plugin_module('event', 'SmpEventExam.php', 'SmpEventExam', 'SMP Event Exam');
qa_register_plugin_module('event', 'SmpEventBlog.php', 'SmpEventBlog', 'SMP Event Blog');
qa_register_plugin_module('event', 'SmpEventJob.php', 'SmpEventJob', 'SMP Event Job');

// Register token expiry checker (runs daily)
qa_register_plugin_module('process', 'SmpTokenChecker.php', 'SmpTokenChecker', 'SMP Token Checker');

// Register daily poster (QOTD & Quote of the Day)
qa_register_plugin_module('process', 'SmpDailyPoster.php', 'SmpDailyPoster', 'SMP Daily Poster');

// Register Google OAuth callback page
qa_register_plugin_module('page', 'SmpOAuthCallback.php', 'SmpOAuthCallback', 'SMP OAuth Callback');

// Register layer for manual share checkboxes on post pages
qa_register_plugin_layer('SmpLayer.php', 'Social Media Poster Layer');

// Register layer for user profile Social Sharing tab
qa_register_plugin_layer('SmpUserPage.php', 'Social Media User Profile Layer');
