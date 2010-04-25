<?php

/**
 * Seamlessly integrate Amazon S3 with your ExpressionEngine website.
 *
 * @package   	BucketList
 * @author    	Stephen Lewis <addons@experienceinternet.co.uk>
 * @copyright 	Copyright (c) 2009-2010, Stephen Lewis
 * @link      	http://experienceinternet.co.uk/bucketlist/
 */

$L = array(

// Site settings.
'site_settings_heading'	=> 'BucketList Settings',
'access_key_id'			=> 'Access Key ID',
'allow_upload'			=> 'Allow Users to Upload Files?',
'allow_upload_hint'		=> 'Any uploaded files will have their permission set to "Public Read".',
'secret_access_key'		=> 'Secret Access Key',
'custom_url'			=> 'Use Custom URL?',
'custom_url_hint'		=> 'Be sure to <a href="http://www.wrichards.com/blog/2009/02/customize-your-amazon-s3-url/">configure your CNAME records</a> before setting this to yes.',

'use_ssl'				=> 'Use SSL?',
'yes'					=> 'Yes',
'no'					=> 'No',

'cache_duration'		=> 'Cache duration',
'cache_duration_hint'	=> "Caching the list of your Amazon S3 files results in fewer queries and lower costs.",
'5_min'					=> '5 minutes',
'10_min'				=> '10 minutes',
'15_min'				=> '15 minutes',
'30_min'				=> '30 minutes',
'45_min'				=> '45 minutes',
'60_min'				=> '1 hour',
'90_min'				=> '1.5 hours',
'120_min'				=> '2 hours',
'240_min'				=> '4 hours',
'360_min'				=> '6 hours',
'480_min'				=> '8 hours',

// Field settings.
'member_group'			=> 'Member Group',
'settings'				=> 'Settings',
'toggle_show'			=> 'Show / hide',
'toggle_upload'			=> 'Allow / disallow upload',
'toggle_all_files'		=> 'Show all files / show only my uploads',

'missing_credentials'	=> 'Please enter your Amazon S3 account details on the extension settings screen.',
'no_buckets'			=> 'There are no available buckets for this account.',
'no_available_buckets'	=> 'There are no available folders for this field.',

'loading' 				=> 'Loading&hellip;',
'no_items'				=> 'No available items.',
'missing_path'			=> 'Missing or invalid path.',

// Uploading.
'upload_failure'	=> 'Upload failed.',
'upload_here'		=> 'Upload a file to this location',
'upload_success'	=> 'Uploaded ',

'confirm_exit' =>
"Some of your files are still uploading. If you leave or reload this page, they may not be saved correctly. Are you sure you wish to continue?",

// All done.
'' => ''

);

/* End of file	: lang.bucketlist.php */
/* Location		: /system/language/english/lang.bucketlist.php */