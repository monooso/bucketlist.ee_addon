<?php

if ( ! defined('EXT'))
{
	exit('Invalid file request');
}

/**
 * Seamlessly integrate Amazon S3 with your ExpressionEngine website.
 *
 * @package   	BucketList
 * @version   	1.2.3
 * @author    	Stephen Lewis <addons@experienceinternet.co.uk>
 * @copyright 	Copyright (c) 2009-2010, Stephen Lewis
 * @link      	http://experienceinternet.co.uk/bucketlist/
 */

require_once 'resources/S3.php';

class Bucketlist extends Fieldframe_Fieldtype {
	
	/* --------------------------------------------------------------
	 * PUBLIC PROPERTIES
	 * ------------------------------------------------------------ */
	
	/**
	 * Default site settings.
	 *
	 * @access	public
	 * @var 	array
	 */
	public $default_site_settings = array(
		'access_key_id'		=> '',
		'secret_access_key'	=> '',
		'cache_duration' 	=> '3600',		// 60 minutes
		'use_ssl' 			=> 'n',
		'custom_url'		=> 'n'
	);
	
	/**
	 * Fieldtype extension hooks.
	 *
	 * @access	public
	 * @var 	array
	 */
	public $hooks = array('sessions_start');
	
	/**
	 * Basic fieldtype information.
	 *
	 * @access	public
	 * @var 	array
	 */
	public $info = array(
		'name'				=> 'BucketList',
		'version'			=> '1.2.3',
		'desc'				=> 'Seamlessly integrate Amazon S3 with your ExpressionEngine site.',
		'docs_url'			=> 'http://experienceinternet.co.uk/bucketlist/',
		'versions_xml_url'	=> 'http://experienceinternet.co.uk/addon-versions.xml'
	);

	/**
	 * Fieldtype requirements.
	 *
	 * @access	public
	 * @var 	array
	 */
	public $requirements = array(
		'ff'        => '1.4',
		'cp_jquery' => '1.1'
	);
	
	
	/* --------------------------------------------------------------
	 * PRIVATE PROPERTIES
	 * ------------------------------------------------------------ */
	
	/**
	 * Member groups with admin or SAEF-posting privileges.
	 *
	 * @access	private
	 * @var 	array
	 */
	private $_admin_member_groups = array();
	
	/**
	 * The class name.
	 *
	 * @access	private
	 * @var 	string
	 */
	private $_class = '';
	
	/**
	 * Demo mode doesn't actually upload the files to Amazon S3.
	 *
	 * @access  private
	 * @var   	bool
	 */
	private $_demo = FALSE;
	
	/**
	 * Lower-class classname.
	 *
	 * @access	private
	 * @var 	string
	 */
	private $_lower_class = '';
	
	/**
	 * Basic member information.
	 *
	 * @access	private
	 * @var 	array
	 */
	private $_member_data = array();
	
	/**
	 * The Session namespace.
	 *
	 * @access	private
	 * @var 	string
	 */
	private $_namespace = '';
	
	/** 
	 * Saved field settings. Note that we can't use the variable
	 * $_field_settings, as that overwrites a private FieldFrame
	 * property, and breaks everything.
	 *
	 * @access	private
	 * @var 	array
	 */
	private $_saved_field_settings = array();
	
	/**
	 * The site ID.
	 *
	 * @access	private
	 * @var 	string
	 */
	private $_site_id = '';
	
	/**
	 * Files just-uploaded to S3.
	 *
	 * @access	private
	 * @var 	array
	 */
	private $_uploads = array();
	
	/**
	 * Is Matrix 2 installed?
	 *
	 * @access	private
	 * @var 	bool
	 */
	private $_has_matrix_2 = FALSE;


	/**
	 * ----------------------------------------------------------------
	 * PRIVATE METHODS
	 * ----------------------------------------------------------------
	 */
	
	/**
	 * Add a bucket item to the database, if it doesn't already exist. If the item was added,
	 * the method returns 1. If the item was already present, the method returns 0. If an error
	 * occurred, the method returns FALSE.
	 *
	 * In other words use === when evaluating the response.
	 *
	 * @access	private
	 * @param	array 		$item			A bucket item.
	 * @param 	string		$bucket_name	The bucket to which to add the items.
	 * @return	int|bool
	 */
	private function _add_bucket_item_to_db($item = array(), $bucket_name = '')
	{
		global $DB;
		
		// No trainers mate.
		if ( ! $item OR ! $bucket_name OR ! is_array($item))
		{
			return FALSE;
		}
		
		// Is this a valid bucket?
		if ( ! $bucket = $this->_load_bucket_from_db($bucket_name))
		{
			return FALSE;
		}
		
		if ( ! $valid_item = $this->_validate_item($item))
		{
			return FALSE;
		}
		
		// Does this item already exist in the database?
		$db_item = $DB->query("SELECT item_id
			FROM exp_bucketlist_items
			WHERE bucket_id = '" .$DB->escape_str($bucket['bucket_id']) ."'
			AND site_id = '{$this->_site_id}'
			AND item_name = '" .$DB->escape_str($valid_item['item_name']) ."'
			AND item_path = '" .$DB->escape_str($valid_item['item_path']) ."'
			LIMIT 1");
			
		if ($db_item->num_rows == 1)
		{
			return 0;
		}

		// Add a couple of extra bits to the $valid_item array.
		$valid_item['bucket_id'] = $DB->escape_str($bucket['bucket_id']);
		$valid_item['site_id'] = $this->_site_id;
		
		// Add the item to the database.
		$DB->query($DB->insert_string('exp_bucketlist_items', $valid_item));
		
		return $DB->affected_rows;
		
	}
	
	
	/**
	 * Duplicate of Fieldframe_Main->_array_ascii_to_entities. Reproduced here, as the
	 * method is technically private.
	 *
	 * @see		http://pixelandtonic.com/fieldframe/
	 * @access	private
	 * @param 	mixed 		$vals		The value to convert.
	 * @return 	string
	 */
	private function _array_ascii_to_entities($vals)
	{
		if (is_array($vals))
		{
			foreach ($vals as &$val)
			{
				$val = $this->_array_ascii_to_entities($val);
			}
		}
		else
		{
			global $REGX;
			$vals = $REGX->ascii_to_entities($vals);
		}

		return $vals;
	}
	
	
	/**
	 * Duplicate of Fieldframe_Main->_array_entities_to_ascii. Reproduced here, as the
	 * method is technically private.
	 *
	 * @see		http://pixelandtonic.com/fieldframe/
	 * @access	private
	 * @param 	mixed 		$vals		The value to convert.
	 * @return 	string
	 */
	private function _array_entities_to_ascii($vals)
	{
		if (is_array($vals))
		{
			foreach ($vals as &$val)
			{
				$val = $this->_array_entities_to_ascii($val);
			}
		}
		else
		{
			global $REGX;
			$vals = $REGX->entities_to_ascii($vals);
		}
		
		return $vals;
	}
	
	
	/**
	 * Builds the branch HTML.
	 *
	 * @access	private
	 * @param	string		$tree_path		The path from the root of the tree.
	 * @param	string		$field_id		The BucketList field ID.
	 * @return	string
	 */
	private function _build_branch_ui($tree_path = '', $field_id = '')
	{
		global $LANG;
		
		/**
		 * This method is called from sessions_start, which runs before
		 * the global $LANG variable is set.
		 *
		 * Just in case we ever need to run it at another point, we check
		 * if the Language class exists, before manually instantiating it.
		 */
		
		if ( ! isset($LANG))
		{
			require PATH_CORE .'core.language' .EXT;
			$LANG = new Language();
		}
		
		$LANG->fetch_language_file($this->_lower_class);
		
		// Initialise the return HTML.
		$html = '';
		
		// Be reasonable, or get out.
		if ( ! $tree_path OR ! $field_id)
		{
			$html .= '<ul style="display : none;">';
			$html .= '<li class="bl-empty">' .$LANG->line('invalid_path') .'</li>';
			$html .= '</ul>';
			
			return $html;
		}
		
		// Extract the bucket name from the tree path.
		$bucket_name = substr($tree_path, 0, strpos($tree_path, '/'));
		
		// Extract the item path (the full tree path, minus the bucket).
		$item_path = substr($tree_path, strlen($bucket_name) + 1);
		
		// Determine the member group settings.
		$group_settings = $this->_get_group_field_settings($this->_member_data['group_id']);
		
		// Do we have settings for this bucket / path?
		$path_settings = array_key_exists($bucket_name, $group_settings['paths'])
			? $group_settings['paths'][$bucket_name]
			: $this->_get_default_path_settings();
		
		// Retrieve the bucket items.
		if ($items = $this->_load_bucket_items($bucket_name, $field_id))
		{
			/**
			 * Is the member permitted to see files that he hasn't personally uploaded?
			 * If not, we've got some filtering to do.
			 */
			
			if ($path_settings['all_files'] != 'y')
			{
				$member_files = array();
				
				// Retrieve an array of the member's uploaded files.
				$member_uploads = $this->_load_member_uploads();
				
				// Filter the available files against the member's uploads.
				foreach ($items['files'] AS $f)
				{
					foreach ($member_uploads AS $m)
					{
						if ($m['item_path'] == $f['item_path']
							&& $m['bucket_name'] == $bucket_name)
						{
							$member_files[] = $f;
							break;
						}
					}
				}
				
				$items['files'] = $member_files;
			}
			
			// Merge the files and folders, so we can process them in a single loop.
			$files_and_folders = array_merge($items['folders'], $items['files']);
			
			$files_html = $folders_html = '';
			
			// The pattern to find the files and folders under the current item path.
			$pattern = '/^' .preg_quote(stripslashes($item_path), '/') .'([^\/]+)\/?$/';
			
			foreach ($files_and_folders AS $f)
			{
				if (preg_match($pattern, $f['item_path'], $matches))
				{
					/**
					 * We want to return the full tree path: the bucket name, plus
					 * the item path.
					 *
					 * We also URL encode the path, in case it contains quotes and the like.
					 */
					
					$f['item_path'] = rawurlencode($bucket_name .'/' .$f['item_path']);
					$f['item_extension'] = strtolower($f['item_extension']);
					
					$item_name = rtrim($matches[1], '/');
					
					// Add items to our folders or files lists.
					if ($f['item_is_folder'] == 'y')
					{
						$folders_html .= "<li class='bl-collapsed bl-directory'>
							<a href='#' rel='{$f['item_path']}'>{$item_name}</a></li>";
					}
					else
					{
						$files_html .= "<li class='bl-ext-{$f['item_extension']} bl-file'>
							<a href='#' rel='{$f['item_path']}'>{$item_name}</a></li>";
					}
				}
			}
			
			$html .= $folders_html .$files_html;
		}
		
		// If we have no items to display, and uploading is not allowed, display an 'empty' message.
		if ( ! $html && $path_settings['allow_upload'] != 'y')
		{
			$html .= '<li class="bl-empty">' .$LANG->line('no_items') .'</li></ul>';
		}
		
		// Include upload link?
		if ($path_settings['allow_upload'] == 'y')
		{
			$html = '<li class="bl-upload"><a href="#">' .$LANG->line('upload_here') .'</a></li>' .$html;
		}
		
		// Wrap everything in a list.
		$html = '<ul style="display : none;">' .$html .'</ul>';
		
		return $html;
		
	}
	
	
	/**
	 * Builds the 'root' HTML. That is, the buckets.
	 *
	 * @access	private
	 * @param 	array 		$settings		Field or cell settings.
	 * @return 	string
	 */
	private function _build_root_ui($settings = array())
	{	
		global $LANG, $SESS;
		
		/**
		 * This method is called from sessions_start, which runs before
		 * the global $LANG variable is set.
		 *
		 * Just in case we ever need to run it at another point, we check
		 * if the Language class exists, before manually instantiating it.
		 */
		
		if ( ! isset($LANG))
		{
			require PATH_CORE .'core.language' .EXT;
			$LANG = new Language();
		}
		
		$LANG->fetch_language_file($this->_lower_class);
		
		// Determine which buckets are available.
		$group_settings = $this->_get_group_field_settings($this->_member_data['group_id']);
		
		$available_buckets = array();
		foreach ($group_settings['paths'] AS $path_settings)
		{
			if ($path_settings['show'] == 'y')
			{
				$available_buckets[] = $path_settings['path'];
			}
		}
		
		/**
		 * Note that we're explicitly loading the buckets from the database.
		 *
		 * This is because the display_field method, called when the field is
		 * first, um, displayed, runs update_buckets_from_s3, to make sure we
		 * have an up-to-date list of buckets.
		 *
		 * REMEMBER:
		 * The cache date is irrelevant as far as buckets are concerned. Whenever
		 * the field is display, we check that our list of buckets is still valid.
		 *
		 * The bucket items are the things that get cached for the period set by
		 * the user.
		 */
		
		if ( ! $buckets = $this->_load_all_buckets_from_db($available_buckets))
		{
			$html = '<p class="bl-alert">' .$LANG->line('no_buckets') .'</p>';
		}
		else
		{
			$html = '<ul>';
		
			foreach ($buckets AS $bucket)
			{
				$html .= '<li class="bl-directory bl-bucket bl-collapsed">';
				
				/**
				 * Note the addition of a forward slash after the bucket name.
				 * This saves us a lot of hassle, as it now means that we can
				 * treat paths with and without 'folders' the same way when
				 * they are returned to us.
				 */
				
				$html .= '<a href="#" rel="' .rawurlencode($bucket['bucket_name'] .'/') .'">' .$bucket['bucket_name'] .'</a></li>';
			}
		
			$html .= '</ul>';
		}
		
		return $html;
	}
	
	
	/**
	 * Builds the pre-1.2 update settings array for a single field or FF Matrix cell.
	 *
	 * @access	private
	 * @param	array		$current_settings		The unserialised settings, pulled from the database.
	 * @return	array
	 */
	private function _build_update_settings($current_settings = array())
	{
		$new_settings = array('member_groups' => array());
		
		// Determine the site-wide "allow upload" setting.
		$allow_upload = array_key_exists('allow_upload', $this->site_settings)
			? $this->site_settings['allow_upload']
			: 'y';
			
		// Retrieve all the applicable member groups.
		if ( ! $this->_admin_member_groups)
		{
			$this->_admin_member_groups = $this->_load_admin_member_groups();
		}
		
		// Retrieve all the buckets.
		$all_buckets = $this->_load_all_buckets_from_db();
		
		// Which buckets are available for this field?
		$available_buckets = isset($current_settings['available_buckets']) && is_array($current_settings['available_buckets'])
			? $current_settings['available_buckets']
			: array();
		
		// Loop through all the member groups.
		foreach ($this->_admin_member_groups AS $member_group)
		{
			$member_group_paths = array();
			$new_settings['member_groups'][$member_group['group_id']] = array('paths' => array());
			
			// Loop through all the buckets.
			foreach ($all_buckets AS $bucket)
			{
				$path_settings = array(
					'all_files'		=> 'y',
					'allow_upload'	=> $allow_upload,
					'path'			=> $bucket['bucket_name'],
					'show'			=> in_array($bucket['bucket_name'], $available_buckets) ? 'y' : 'n'
				);
				
				$member_group_paths[] = $path_settings;
			}
			
			$new_settings['member_groups'][$member_group['group_id']]['paths'] = $member_group_paths;
		}
		
		return $new_settings;
	}
	
	
	/**
	 * Checks that the S3 credentials have been set. Makes not attempt to check their validity.
	 *
	 * @access  private
	 * @return  bool
	 */
	private function _check_s3_credentials()
	{
		return (isset($this->site_settings['access_key_id'])
			&& $this->site_settings['access_key_id'] !== ''
			&& isset($this->site_settings['secret_access_key'])
			&& $this->site_settings['secret_access_key'] !== '');
	}
	
	
	/**
	 * Extracts the current member's privileges from the supplied settings.
	 *
	 * @access	private
	 * @param	array		$settings		Field or cell settings.
	 * @return	array
	 */
	private function _extract_member_privileges($settings = '')
	{
		global $SESS;
		
		// Locked-down by default.
		$privileges = array(
			'allow_upload'		=> FALSE,
			'allow_browse'		=> FALSE,
			'restrict_browse'	=> TRUE
		);
		
		$member_group_id = isset($SESS->userdata['group_id'])
			? $SESS->userdata['group_id']
			: isset($this->_member_data['group_id']) ? $this->_member_data['group_id'] : '';
		
		if ($member_group_id && isset($settings['member_groups']) && isset($settings['member_groups'][$member_group_id]))
		{
			// Extract the member group settings.
			$member_settings = $settings['member_groups'][$member_group_id];
		
			// Update the default privileges.
			$privileges['allow_upload'] = isset($member_settings['allow_upload'])
				? ($member_settings['allow_upload'] == 'y')
				: FALSE;
			
			$privileges['allow_browse'] = isset($member_settings['allow_browse'])
				? ($member_settings['allow_browse'] == 'y')
				: FALSE;
			
			$privileges['restrict_browse'] = isset($member_settings['restrict_browse'])
				? ($member_settings['restrict_browse'] == 'y')
				: TRUE;
		}
		
		return $privileges;
	}
	
	
	/**
	 * Forces an update of the fieldtype. Used during beta testing, when the
	 * version number updates are not recognised by FieldFrame.
	 *
	 * @access	private
	 * @return	void
	 */
	private function _force_update()
	{
		global $DB, $PREFS;
		
		/**
		 * No messing about. Just blat the lot, and start again with
		 * a clean database cache.
		 */
		
		$sql[] = 'DROP TABLE IF EXISTS exp_bucketlist_buckets';
		$sql[] = 'DROP TABLE IF EXISTS exp_bucketlist_files';		// Pre-0.8.0 hangover.
		$sql[] = 'DROP TABLE IF EXISTS exp_bucketlist_items';
		$sql[] = 'DROP TABLE IF EXISTS exp_bucketlist_uploads';
		
		/**
		 * @since 1.1.3
		 * Some older MySQL installations use MyISAM. However, new tables are automatically
		 * created using INNODB, resulting in problems with foreign keys.
		 *
		 * We can either drop the foreign keys, which would be tantamount to admitting defeat,
		 * or we can determine the engine, and explicitly specify it. We do the latter.
		 */
		
		if (version_compare(mysql_get_server_info(), '5.0.0', '<'))
		{
			// We take a punt.
			$engine = 'MyISAM';
		}
		else
		{
			$db_engine = $DB->query("SELECT `ENGINE` AS `engine`
				FROM information_schema.TABLES
				WHERE TABLE_SCHEMA =  '" .$PREFS->ini('db_name') ."'
				AND TABLE_NAME = 'exp_sites'
				LIMIT 1");

			if ($db_engine->num_rows !== 1)
			{
				exit('Unable to determine your database engine.');
			}

			$engine = $db_engine->row['engine'];
		}
		
		$sql[] = "CREATE TABLE IF NOT EXISTS exp_bucketlist_buckets (
				bucket_id int(10) unsigned NOT NULL auto_increment,
				site_id int(5) unsigned NOT NULL default 1,
				bucket_name varchar(255) NOT NULL,
				bucket_items_cache_date int(10) unsigned NOT NULL default 0,
				CONSTRAINT pk_buckets PRIMARY KEY(bucket_id),
				CONSTRAINT fk_bucket_site_id FOREIGN KEY(site_id) REFERENCES exp_sites(site_id),
				CONSTRAINT uk_site_id_bucket_name UNIQUE (site_id, bucket_name))
			ENGINE = {$engine}";
		
		$sql[] = "CREATE TABLE IF NOT EXISTS exp_bucketlist_items (
				item_id int(10) unsigned NOT NULL auto_increment,
				site_id int(5) unsigned NOT NULL default 1,
				bucket_id int(10) unsigned NOT NULL,
				item_path text NOT NULL,
				item_name varchar(255) NOT NULL,
				item_is_folder char(1) NOT NULL default 'n',
				item_size int(10) unsigned NOT NULL,
				item_extension varchar(10) NOT NULL,
				CONSTRAINT pk_items PRIMARY KEY(item_id),
				CONSTRAINT fk_item_site_id FOREIGN KEY(site_id) REFERENCES exp_sites(site_id),
				CONSTRAINT fk_item_bucket_id FOREIGN KEY(bucket_id) REFERENCES exp_bucketlist_buckets(bucket_id))
			ENGINE = {$engine}";
			
		$sql[] = "CREATE TABLE IF NOT EXISTS exp_bucketlist_uploads (
				upload_id int(10) unsigned NOT NULL auto_increment,
				site_id int(5) unsigned NOT NULL default 1,
				member_id int(10) unsigned NOT NULL,
				bucket_id int(10) unsigned NOT NULL,
				item_path varchar(1000) NOT NULL,
				CONSTRAINT pk_uploads PRIMARY KEY(upload_id),
				CONSTRAINT fk_upload_site_id FOREIGN KEY(site_id) REFERENCES exp_sites(site_id),
				CONSTRAINT fk_upload_member_id FOREIGN KEY(member_id) REFERENCES exp_members(member_id),
				CONSTRAINT fk_upload_bucket_id FOREIGN KEY(bucket_id) REFERENCES exp_bucketlist_buckets(bucket_id))
			ENGINE = {$engine}";
		
		foreach ($sql AS $query)
		{
			$DB->query($query);
		}
	}
	
	
	/**
	 * Returns the default member group bucket or folder settings.
	 *
	 * @access	private
	 * @return	array
	 */
	private function _get_default_path_settings()
	{
		return array(
			'all_files'		=> 'y',
			'allow_upload'	=> 'y',
			'show'			=> 'y'
		);
	}
	
	
	/**
	 * Extracts the field settings for the specified member group from
	 * the $this->_saved_field_settings array.
	 *
	 * @access	private
	 * @param	string		$group_id		The member group ID.
	 * @return	array
	 */
	private function _get_group_field_settings($group_id = '')
	{
		// A basic shell, so other methods can rely on the paths key existing.
		$group_settings = array('paths' => array());
		
		if ( ! $group_id
			OR ! $this->_saved_field_settings
			OR ! isset($this->_saved_field_settings['member_groups'][$group_id]))
		{
			return $group_settings;
		}
		
		foreach ($this->_saved_field_settings['member_groups'][$group_id]['paths'] AS $path_settings)
		{
			/**
			 * The paths array becomes key => value, to make it easier to check for the existence
			 * of a path with array_key_exists.
			 */
			
			$group_settings['paths'][$path_settings['path']] = array(
				'all_files'		=> $path_settings['all_files'],
				'allow_upload'	=> $path_settings['allow_upload'],
				'path'			=> $path_settings['path'],
				'show'			=> $path_settings['show']
			);
		}
		
		return $group_settings;
	}
	
	
	/**
	 * Returns the Matrix version.
	 *
	 * @access	private
	 * @return	string
	 */
	private function _get_matrix_version()
	{
		global $DB;
		
		$version = 0;
		
		$db_version = $DB->query("SELECT class, version
			FROM exp_ff_fieldtypes
			WHERE class IN('ff_matrix', 'matrix')");
			
		if ($db_version->num_rows > 0)
		{
			foreach ($db_version->result AS $dbv)
			{
				$$dbv['class'] = $dbv['version'];
			}
			
			$version = isset($matrix) ? $matrix : $ff_matrix;
		}
		
		return $version;
	}
	
	
	/**
	 * Checks whether the specified item exists on the S3 server.
	 *
	 * @access	private
	 * @param	string		$item_name		The full item path and name, including the bucket.
	 * @return	bool
	 */
	private function _item_exists_on_s3($item_name = '')
	{
		// Clearly not, muppet.
		if ( ! $item_name)
		{
			return FALSE;
		}
		
		// Separate out the bucket name.
		$bucket_and_path = $this->_split_bucket_and_path_string($item_name);
		
		if ( ! $bucket_and_path['bucket'])
		{
			return FALSE;
		}
		
		// Make the call.
		$s3 = new S3($this->site_settings['access_key_id'], $this->site_settings['secret_access_key'], FALSE);
		return @$s3->getObjectInfo($bucket_and_path['bucket'], $bucket_and_path['item_path'], FALSE);
		
	}
	
	
	/**
	 * Loads all the 'admin' member groups from the database, and parses them
	 * into an array. Admin member groups are defined here as an member group
	 * that can:
	 * 1. Access the CP and the publish or edit pages; or
	 * 2. Post to a weblog (which covers SAEF usage)
	 *
	 * @access	private
	 * @return	array
	 */
	private function _load_admin_member_groups()
	{
		global $DB;
		
		$db_cp_groups = $DB->query("SELECT mg.group_id, mg.group_title
			FROM exp_member_groups AS mg
			WHERE mg.site_id = '{$this->_site_id}'
			AND mg.can_access_cp = 'y'
			AND (mg.can_access_publish = 'y' OR mg.can_access_edit = 'y')
			ORDER BY mg.group_title ASC");
			
		$db_saef_groups = $DB->query("SELECT mg.group_id, mg.group_title
			FROM exp_member_groups AS mg
			INNER JOIN exp_weblog_member_groups AS wmg
			ON wmg.group_id = mg.group_id
			WHERE mg.site_id = '{$this->_site_id}'
			AND (mg.can_access_cp <> 'y' OR (mg.can_access_publish <> 'y' AND mg.can_access_edit <> 'y')) 
			GROUP BY mg.group_id
			ORDER BY mg.group_title ASC");
		
		// Parse the results.
		$member_groups = array();
		
		if ($db_cp_groups->num_rows > 0)
		{
			foreach ($db_cp_groups->result AS $db_cp_group)
			{
				$member_groups[$db_cp_group['group_id']] = array(
					'group_id'		=> $db_cp_group['group_id'],
					'group_title'	=> $db_cp_group['group_title']
				);
			}
		}
		
		if ($db_saef_groups->num_rows > 0)
		{
			foreach ($db_saef_groups->result AS $db_saef_group)
			{
				$member_groups[$db_saef_group['group_id']] = array(
					'group_id'		=> $db_saef_group['group_id'],
					'group_title'	=> $db_saef_group['group_title']
				);
			}
		}
		
		usort($member_groups, array($this, '_member_group_sort'));
		return $member_groups;
	}
	
	
	/**
	 * Retrieves all the buckets from the database, and filter them against the available buckets.
	 *
	 * @access	private
	 * @param 	mixed 		$filter		An array containing the available buckets, or FALSE to return all.
	 * @return 	array
	 */
	private function _load_all_buckets_from_db($filter = FALSE)
	{
		global $DB;
		
		$sql = "SELECT
				bucket_id, bucket_items_cache_date, bucket_name, site_id
			FROM exp_bucketlist_buckets
			WHERE site_id = '{$this->_site_id}'";
			
		$sql .= is_array($filter) ? " AND bucket_name IN('" .implode("', '", $filter) ."')" : '';
		$sql .= ' ORDER BY bucket_name ASC';
		
		$db_buckets = $DB->query($sql);
			
		if ($db_buckets->num_rows == 0)
		{
			return array();
		}
		
		// Initialise the return array.
		$buckets = array();
		
		foreach ($db_buckets->result AS $db_bucket)
		{
			if ($bucket = $this->_validate_bucket($db_bucket))
			{
				$buckets[] = $bucket;
			}
		}
		
		return $buckets;
	}
	
	
	/**
	 * Retrieves a bucket from the database, given its name.
	 *
	 * @access	private
	 * @param 	string		$bucket_name		The name of the bucket.
	 * @return 	array|bool
	 */
	private function _load_bucket_from_db($bucket_name = '')
	{
		global $DB;
		
		if ( ! $bucket_name)
		{
			return FALSE;
		}
		
		$db_bucket = $DB->query("SELECT
				bucket_id, bucket_items_cache_date, bucket_name, site_id
			FROM exp_bucketlist_buckets
			WHERE bucket_name = '" .$DB->escape_str($bucket_name) ."'
			AND site_id = '{$this->_site_id}'
			LIMIT 1");
			
		return ($this->_validate_bucket($db_bucket->row));
	}
	
	
	/**
	 * Loads a bucket's contents.
	 *
	 * @access	private
	 * @param 	string		$bucket_name		The name of the bucket.
	 * @return 	array
	 */
	private function _load_bucket_items($bucket_name = '')
	{
		// Be reasonable.
		if ( ! $bucket_name OR ( ! $bucket = $this->_load_bucket_from_db($bucket_name)))
		{
			return FALSE;
		}
		
		/**
		 * @since 1.1.2
		 *
		 * A few changes:
		 * - Dispensed with Session cache, as this method is always called via AJAX.
		 * - Moved S3 updates to the display_field method, so we only ever check the
		 *	 database now. This fixed a bug whereby bucket items could be duplicated
		 *	 in the database, due to overlapping AJAX calls.
		 */
		
		return $this->_load_bucket_items_from_db($bucket_name);
	}
	
	
	/**
	 * Retrieve a bucket's contents from the database.
	 *
	 * @access	private
	 * @param 	string		$bucket_name		The name of the bucket.
	 * @return 	array
	 */
	private function _load_bucket_items_from_db($bucket_name = '')
	{
		global $DB;
		
		// NOTE: DOES NOT check whether the items cache has expired.
		
		// Talk sense man.
		if ( ! $bucket_name OR ( ! $bucket = $this->_load_bucket_from_db($bucket_name)))
		{
			return FALSE;
		}
		
		// Load the items from the database.
		$db_items = $DB->query("SELECT
				item_id, item_path, item_name, item_size, item_extension, item_is_folder
			FROM exp_bucketlist_items
			WHERE bucket_id = '" .$DB->escape_str($bucket['bucket_id']) ."'
			AND site_id = '{$this->_site_id}'
			ORDER BY item_name ASC");
			
		if ($db_items->num_rows == 0)
		{
			return array();
		}
		
		// Parse the data into arrays ('folders' and 'files').
		$folders = $files = array();
		
		foreach ($db_items->result AS $db_item)
		{
			$item = $this->_validate_item($db_item);
			
			if ($item)
			{
				$item['item_is_folder'] == 'y' ? $folders[] = $item : $files[] = $item;
			}
		}
		
		// Do we have any items?
		$items = array();
		
		if ($folders OR $files)
		{
			$items['folders'] 	= $folders;
			$items['files']		= $files;
		}
		
		// Return the data.
		return $items;
	}
	
	
	/**
	 * Loads the field or cell settings from the database, and parses them into an array.
	 * Called from sessions_start.
	 *
	 * @access	private
	 * @param	string		$full_field_id		The ID of the field or cell, in the form field_id_99[1][2].
	 * @return	array
	 */
	private function _load_field_settings($full_field_id = '')
	{
		global $DB;
		
		// All you ever do's initialize, sing it with me now.
		$settings 	= array();
		$field_id 	= '';
		$row_id		= '';
		$cell_id 	= '';
		
		/**
		 * @since 1.2 : RegularExpression rewritten to accommodate the following:
		 *
		 * - standard field ID				: field_id_1
		 * - FF Matrix 1.x cell ID format	: field_id_1[2][3]
		 * - Matrix 2.x cell ID format		: field_id_1[row_new_2][col_id_3] OR field_id_1[row_id_2][col_id_3]
		 */
		
		if (preg_match('/field_id_([0-9]+)(\[[^0-9]*([0-9]+)\]\[[^0-9]*([0-9]+)\])?/i', $full_field_id, $matches))
		{
			$field_id 	= $matches[1];
			$row_id		= isset($matches[3]) ? $matches[3] : '';
			$col_id 	= isset($matches[4]) ? $matches[4] : '';
		}
		
		if ($field_id)
		{
			// Load the field settings.
			$db_settings = $DB->query("SELECT ff_settings
				FROM exp_weblog_fields
				WHERE field_id = '{$field_id}'
				AND site_id = '{$this->_site_id}'
				LIMIT 1");
			
			if ($db_settings->num_rows === 1)
			{
				$settings = $this->_unserialize($db_settings->row['ff_settings']);
				
				// FF Matrix / Matrix.
				if ($col_id !== '')
				{
					if ( ! $this->_has_matrix_2)
					{
						// FF Matrix 1.x
						if (isset($settings['cols'][$col_id]['settings']))
						{
							$settings = $settings['cols'][$col_id]['settings'];
						}
					}
					else
					{
						// Matrix 2.
						if (isset($settings['col_ids']) && in_array($col_id, $settings['col_ids']))
						{
							/**
							 * @since 1.2.1
							 * For reasons unknown, the Field ID is occasionally blank. This seems like
							 * a Matrix bug to me, and I've contacted Brandon. In the interim, we can
							 * get away with just the column ID, as that's the table index anyway.
							 *
							
								$db_matrix_settings = $DB->query("SELECT col_settings
									FROM exp_matrix_cols
									WHERE col_id = '{$col_id}'
									AND col_type = '{$this->_lower_class}'
									AND field_id = '{$field_id}'
									LIMIT 1");
							 
							 */
							
							$db_matrix_settings = $DB->query("SELECT col_settings
								FROM exp_matrix_cols
								WHERE col_id = '{$col_id}'
								AND col_type = '{$this->_lower_class}'
								LIMIT 1");

							if ($db_matrix_settings->num_rows !== 1)
							{
								$settings = $this->_get_default_path_settings();
							}
							else
							{
								$settings = $this->_unserialize($db_matrix_settings->row['col_settings'], TRUE, TRUE);
							}
						}
					}
				}
			}
		}
		
		return $settings;
	}
	
	
	/**
	 * Retrieves an item based on the saved field data.
	 *
	 * @access	private
	 * @param	string			$field_data		The saved field data (a full item path, including bucket).
	 * @return	array|bool
	 */
	private function _load_item_using_field_data($field_data = '')
	{
		global $DB;
		
		if ( ! $field_data)
		{
			return FALSE;
		}
		
		// Trim the bucket from the start of the field data.
		if ($bucket_and_path = $this->_split_bucket_and_path_string($field_data))
		{
			$db_item = $DB->query("SELECT item_extension, item_is_folder, item_name, item_path, item_size
				FROM exp_bucketlist_items AS items
				INNER JOIN exp_bucketlist_buckets AS buckets
				ON buckets.bucket_id = items.bucket_id
				WHERE buckets.bucket_name = '" .$DB->escape_str($bucket_and_path['bucket']) ."'
				AND items.item_path = '" .$DB->escape_str($bucket_and_path['item_path']) ."'
				LIMIT 1");
			
			if ($db_item->num_rows == 1 && $item = $this->_validate_item($db_item->row))
			{
				return $item;
			}
		}
		
		return FALSE;
	}
	
	
	/**
	 * Retrieves the member data. Called from sessions_start.
	 *
	 * @access	private
	 * @return	array
	 */
	private function _load_member_data()
	{
		global $DB, $IN, $SESS;
		
		if ($this->_member_data)
		{
			return $this->_member_data;
		}
		
		$member_data = array(
			'group_id' => '0',
			'member_id' => '0'
		);
		
		if (isset($SESS->userdata['member_id']) && isset($SESS->userdata['group_id']))
		{
			$member_data = $SESS->userdata;
		}
		else
		{
			$ip 	= $DB->escape_str($IN->IP);
			$agent	= $DB->escape_str(substr($IN->AGENT, 0, 50));
		
			/**
			 * Retrieve the Session ID, either from a cookie,
			 * or from GET data.
			 */
		
			if ( ! $session_id = $IN->GBL('sessionid', 'COOKIE'))
			{
				if ( ! $session_id = $IN->GBL('S', 'GET'))
				{
					if ($IN->SID != '')
					{
						$session_id = $IN->SID;
					}
				}
			}
		
			// Retrieve the member ID.
			if ($session_id)
			{
				$db_member = $DB->query("SELECT
						m.member_id,
						m.group_id
					FROM exp_sessions AS s
					INNER JOIN exp_members AS m
					ON m.member_id = s.member_id
					WHERE s.session_id = '" .$DB->escape_str($session_id) ."'
					AND s.ip_address = '{$ip}'
					AND s.user_agent = '{$agent}'
					AND s.site_id = '{$this->_site_id}'"
				);
				
				if ($db_member->num_rows == 1)
				{
					$member_data = array(
						'group_id'	=> $db_member->row['group_id'],
						'member_id'	=> $db_member->row['member_id']
					);
				}
			}
		}
		
		return $member_data;
	}
	
	
	/**
	 * Loads the uploaded files for the specified member.
	 *
	 * @access	private
	 * @param 	string		$member_id		The member ID.
	 * @return	array
	 */
	private function _load_member_uploads($member_id = '')
	{
		global $DB, $SESS;
		
		$uploads = array();
		
		$member_id = isset($SESS->userdata['member_id'])
			? $SESS->userdata['member_id']
			: isset($this->_member_data['member_id']) ? $this->_member_data['member_id'] : '';
		
		$db_uploads = $DB->query("SELECT u.upload_id, u.bucket_id, b.bucket_name, u.item_path
			FROM exp_bucketlist_uploads AS u
			INNER JOIN exp_bucketlist_buckets AS b
			ON b.bucket_id = u.bucket_id
			WHERE u.site_id = '{$this->_site_id}'
			AND u.member_id = '" .$DB->escape_str($member_id) ."'"
		);
		
		if ($db_uploads->num_rows > 0)
		{
			foreach ($db_uploads->result AS $db_upload)
			{
				$uploads[] = array(
					'bucket_id'		=> $db_upload['bucket_id'],
					'bucket_name'	=> $db_upload['bucket_name'],
					'item_path'		=> $db_upload['item_path'],
					'upload_id'		=> $db_upload['upload_id']
				);
			}
		}
		
		return $uploads;
	}
	
	
	/**
	 * Custom sort function, used to order the member groups by title.
	 *
	 * @access	private
	 * @param	array		$member_group_a		The first member group.
	 * @param	array		$member_group_b		The second member group.
	 * @return	int
	 */
	private function _member_group_sort($member_group_a = array(), $member_group_b = array())
	{
		if ( ! array_key_exists('group_title', $member_group_a)
			OR ! array_key_exists('group_title', $member_group_b))
		{
			return 0;
		}
		else
		{
			return strcmp($member_group_a['group_title'], $member_group_b['group_title']);
		}
	}
	
	
	/**
	 * Outputs the supplied HTML with the appropriate headers.
	 *
	 * @access	private
	 * @param	string		$html		The HTML to output.
	 * @return	void
	 */
	private function _output_ajax_response($html = '')
	{
		global $PREFS;
		
		if ($_SERVER['SERVER_PROTOCOL'] == 'HTTP/1.1' OR $_SERVER['SERVER_PROTOCOL'] == 'HTTP/1.0')
		{
			header($_SERVER['SERVER_PROTOCOL'] .' 200 OK', TRUE, 200);
		}
		else
		{
			header('HTTP/1.1 200 OK', TRUE, 200);
		}
		
		header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
		header('Last-Modified: ' .gmdate('D, d M Y H:i:s') .' GMT');
		header('Pragma: no-cache');
		header('Cache-Control: no-cache, must-revalidate');
		header('Content-Type: text/html; charset=' .$PREFS->ini('charset'));
		
		exit($html);
	}
	
	
	/**
	 * Outputs the upload response HTML.
	 *
	 * @access	private
	 * @param	string		$message_data	Message data: status, message, upload_id, list_item.
	 * @return	string
	 */
	private function _output_upload_response($message_data = array())
	{
		global $LANG, $PREFS;

		// Fine, be like that, see what I care.
		if ( ! is_array($message_data))
		{
			$message_data = array();
		}

		// Ever the optimist.
		$default_data = array(
			'status'		=>	'failure',
			'message'		=>	$LANG->line('upload_failure'),
			'upload_id'		=> '',
			'list_item'		=> ''
		);

		$message_data = array_merge($default_data, $message_data);

		// Tidy see.
		$message_data['message'] = htmlspecialchars($message_data['message'], ENT_COMPAT, 'UTF-8');

		/**
		 * Create and return the HTML document. Why, you may ask,
		 * do we not respond with XML, or perhaps even JSON?
		 *
		 * Simple, Internet Explorer and can't handle XML. JSON
		 * is even more problematic.
		 */

		$html = <<<_HTML_
<html>
<head>
	<title>Amazon S3 Response</title>
</head>
<body>
<p id="status">{$message_data['status']}</p>
<p id="message">{$message_data['message']}</p>
<p id="uploadId">{$message_data['upload_id']}</p>
<ul id="listItem">{$message_data['list_item']}</ul>
</body>
</html>
_HTML_;

		// Output the return document.
		$this->_output_ajax_response($html);
	}
	
	
	/**
	 * Parses a single Amazon S3 item, representing a single item of content in a bucket.
	 *
	 * @access	private
	 * @param	array		$s3_item	The S3 item to parse.
	 * @return	array
	 */
	private function _parse_item_s3_result($s3_item = array())
	{
		// Steady butt.
		if ( ! $s3_item OR ! is_array($s3_item))
		{
			return array();
		}
		
		// Do we have the required information?
		$required_fields 	= array('name', 'time', 'size', 'hash');
		$item 				= array();
		$missing_field		= FALSE;
		
		foreach ($required_fields AS $field_id)
		{
			if ( ! array_key_exists($field_id, $s3_item) OR (is_string($s3_item[$field_id]) && $s3_item[$field_id] == ''))
			{
				$missing_field = TRUE;
				break;
			}
		}
		
		if ($missing_field)
		{
			return array();
		}
		
		// Extract the information we require.
		$item = array(
			'item_extension'	=> pathinfo($s3_item['name'], PATHINFO_EXTENSION),
			'item_is_folder'	=> (substr($s3_item['name'], -1) == '/') ? 'y' : 'n',
			'item_name'			=> pathinfo($s3_item['name'], PATHINFO_BASENAME),
			'item_path'			=> $s3_item['name'],
			'item_size'			=> intval($s3_item['size'])
		);
		
		return $item;
	}
	
	
	/**
	 * Forwards the just-uploaded file to Amazon S3, and writes out a
	 * response document based on the success or failure of the operation.
	 *
	 * @access	private
	 * @return	void
	 */
	private function _process_upload()
	{
		global $DB, $IN, $LANG, $SESS;
		
		/**
		 * This is being called from the sessions_start method, so the
		 * $LANG global variable probably hasn't been instantiated yet.
		 */
		
		if ( ! isset($LANG))
		{
			require PATH_CORE .'core.language' .EXT;
			$LANG = new Language();
		}
		
		$LANG->fetch_language_file($this->_lower_class);
		
		// Retrieve the upload ID.
		$upload_id = $IN->GBL('upload_id', 'POST');
		
		/**
		 * The path has been on a round trip from this class, contained in
		 * a rel attribute, and then an input:hidden form element.
		 *
		 * During this entire glorious journey, the JS has not meddled with
		 * the encoding one iota, just so that we can easily decode it here.
		 */
		
		$full_path = rawurldecode($IN->GBL('path', 'POST'));
		$bucket_and_path = $this->_split_bucket_and_path_string($full_path, TRUE);
		
		if ( ! $bucket_and_path['bucket']
			OR ! isset($this->_member_data['group_id'])
			OR ! $group_settings = $this->_get_group_field_settings($this->_member_data['group_id'])
			OR ! array_key_exists($bucket_and_path['bucket'], $group_settings['paths'])
			OR $group_settings['paths'][$bucket_and_path['bucket']]['allow_upload'] != 'y')
		{
			$status = 'failure';
			$message = $LANG->line('upload_failure');
			
			$this->_output_upload_response(array(
				'status'	=> $status,
				'message'	=> $message,
				'upload_id'	=> $upload_id,
				'list_item'	=> ''
			));
			
			return FALSE;
		}
		
		// Upload the file to S3.
		if ( ! $this->_upload_file_to_s3('file', $bucket_and_path['bucket'], $bucket_and_path['item_path']))
		{
			$status = 'failure';
			$message = $LANG->line('upload_failure');
			
			$this->_output_upload_response(array(
				'status'		=> $status,
				'message'		=> $message,
				'upload_id'		=> $upload_id,
				'list_item'		=> ''
			));
			
			return FALSE;
		}
		
		$list_item = '';
		$status = 'success';
		$messages = array();
		
		// As a result of the _upload_to_s3 method, there may be multiple files to process now.
		foreach ($this->_uploads AS $upload)
		{
			// Construct the URI.
			$uri = $upload['path']
				? $upload['path'] .'/' .$upload['file']['name']
				: $upload['file']['name'];
		
			// Extract some file information.
			$item_info = array(
				'item_extension' 	=> pathinfo($upload['file']['name'], PATHINFO_EXTENSION),
				'item_is_folder'	=> 'n',
				'item_name' 		=> $upload['file']['name'],
				'item_path' 		=> $uri,
				'item_size' 		=> $upload['file']['size']
			);
		
		
			/**
			 * Add our item to the database.
			 *
			 * @since 1.2 : we no longer check the result of this operation, and just
			 *				return details of the list item.
			 */
		
			$this->_add_bucket_item_to_db($item_info, $upload['bucket']);
		
			// Record the member ID of the user that uploaded this file.
			if (($bucket = $this->_load_bucket_from_db($upload['bucket']))
				&& ($this->_member_data['member_id']))
			{
				$DB->query($DB->insert_string(
					'exp_bucketlist_uploads',
					array(
						'bucket_id'	=> $DB->escape_str($bucket['bucket_id']),
						'item_path'	=> $uri,
						'member_id'	=> $this->_member_data['member_id'],
						'site_id'	=> $this->_site_id
					)
				));
			}
			
			/**
			 * The `bucketlist_remote_upload_start` hook gives a third-party the opportunity
			 * to change whatever he wants, including the bucket and path.
			 *
			 * This is fine for *additional* files (such as auto-generated thumbnails placed
			 * in a sub-directory), but a real pain if the original file has been relocated.
			 *
			 * The best we can do is check whether the file belongs in the original bucket
			 * and path, before adding it to the list.
			 *
			 * @todo : sort out your bloody naming conventions, you slack bastard.
			 */
			
			if ($bucket_and_path['bucket'] == $upload['bucket']
				&& $bucket_and_path['item_path'] == $upload['path'])
			{
				$list_item .= '<li class="bl-ext-' .strtolower($item_info['item_extension']) .' bl-file">
					<a href="#" rel="' .rawurlencode($bucket_and_path['bucket'] .'/'
					.$item_info['item_path']) .'">' .$item_info['item_name'] .'</a></li>';
					
				// On display a success message for items in the current path, to avoid confusion.
				$messages[] = $upload['file']['name'];
			}
		}
		
		// Output the return document.
		$this->_output_upload_response(array(
			'status'		=> $status,
			'message'		=> $LANG->line('upload_success') .' ' .implode(', ', $messages),
			'upload_id'		=> $upload_id,
			'list_item'		=> $list_item
		));
	}
	
	
	/**
	 * Serializes data. FF Matrix / Matrix store data differently, so we have to do yet more
	 * branching here.
	 *
	 * @access	private
	 * @param 	array 		$vals		The array to serialise.
	 * @param 	bool		$matrix		Is this for use in an FF Matrix / Matrix field?
	 * @return 	string
	 */
	private function _serialize($vals = array(), $matrix = FALSE)
	{
		global $PREFS;
		
		// Deal with Matrix 2 first.
		if ($matrix === TRUE && $this->_has_matrix_2)
		{
			return base64_encode(serialize($vals));
		}
		
		// Everything else.
		if ($PREFS->ini('auto_convert_high_ascii') == 'y')
		{
			$vals = $this->_array_ascii_to_entities($vals);
		}

     	return addslashes(serialize($vals));
	}
	
	
	/**
	 * Splits a 'bucket and item path' string into two separate strings.
	 *
	 * IMPORTANT NOTE:
	 * If the item path is the bucket root (/), the method still expects the
	 * slash to be included. If it's not, an empty value is returned for both
	 * the bucket and path.
	 *
	 * @access	private
	 * @param	string		$full_path			The full 'bucket and item path' string.
	 * @param	bool		$strip_slashes		Strips and forward slashes from the end of the item path.
	 * @return	array|bool
	 */
	private function _split_bucket_and_path_string($full_path = '', $strip_slashes = FALSE)
	{
		$bucket_and_path = array('bucket' => '', 'item_path' => '');
		
		if ( ! $full_path)
		{
			return FALSE;
		}
		
		/**
		 * The following regular expression also contains a little bit
		 * of validation for the bucket name. It's not 100% strict
		 * though, as there's no way we should ever be passed a non-
		 * existent bucket name, never mind an entirely invalid one.
		 */
		
		if (preg_match('/^([0-9a-z]{1}[0-9a-z\.\_\-]{2,254})\/{1}(.*)$/i', $full_path, $matches))
		{
			if ($matches[1] OR $matches[2])
			{
				$bucket_and_path['bucket'] 		= $matches[1];
				$bucket_and_path['item_path']	= $strip_slashes ? rtrim($matches[2], '/') : $matches[2];
			}
		}
		
		return $bucket_and_path;
	}
	
	
	/**
	 * Unserializes data. FF Matrix / Matrix store data differently, so we have to do yet more
	 * branching here.
	 *
	 * @access	private
	 * @param 	string 		$vals		The string to unserialise.
	 * @param 	bool		$convert	Convert high ASCII values, if the PREF is set to 'y'?
	 * @param	bool		$matrix		Is this data from an FF Matrix / Matrix field?
	 * @return 	array
	 */
	private function _unserialize($vals, $convert = TRUE, $matrix = FALSE)
	{
		global $PREFS, $REGX;
		
		// Deal with Matrix 2 first.
		if ($matrix === TRUE && $this->_has_matrix_2)
		{
			return unserialize(base64_decode($vals));
		}
		
		// Everything else.
		if (($tmp_vals = @unserialize($vals)) !== FALSE)
		{
			$vals = $REGX->array_stripslashes($tmp_vals);
			
			if ($convert && $PREFS->ini('auto_convert_high_ascii') == 'y')
			{
				$vals = $this->_array_entities_to_ascii($vals);
			}
		}
		
		return $vals;
	}
	
	
	/**
	 * Attempts to retrieve a bucket's contents from Amazon, and save them
	 * to the database.
	 *
	 * @access	private
	 * @param 	string		$bucket_name		The name of the bucket.
	 * @param 	bool		$force_update		Force an S3 query, regardless of the cache date.
	 * @return 	bool
	 */
	private function _update_bucket_items_from_s3($bucket_name = '', $force_update = FALSE)
	{
		global $DB;
		
		// Is this a valid bucket?
		if ( ! $bucket_name OR ( ! $bucket = $this->_load_bucket_from_db($bucket_name)))
		{
			return FALSE;
		}
		
		// Does this bucket require updating?
		$cache_expiry_date = $bucket['bucket_items_cache_date'] +intval($this->site_settings['cache_duration']);
		if ($cache_expiry_date >= time() && $force_update !== TRUE)
		{
			return FALSE;
		}
		
		// Make the call to Amazon.
		$s3 = new S3($this->site_settings['access_key_id'], $this->site_settings['secret_access_key'], FALSE);
		
		
		/**
		 * @since 1.1.4
		 * The bucket items deletion, and bucket cache date update need to happen regardless of
		 * whether the call to Amazon returned any items.
		 *
		 * This prevents problems with previously-populated buckets that have since been emptied,
		 * and return zero items (i.e. FALSE).
		 */
		
		// Delete any existing bucket items from the database.
		$DB->query("DELETE FROM exp_bucketlist_items
			WHERE bucket_id = {$bucket['bucket_id']}
			AND site_id = {$this->_site_id}");
			
		// Update the bucket cache date.
		$DB->query($DB->update_string(
			'exp_bucketlist_buckets',
			array('bucket_items_cache_date' => time()),
			"bucket_id = {$bucket['bucket_id']}"
		));
		
		
		if ( ! $s3_items = @$s3->getBucket($bucket_name))
		{
			return FALSE;
		}
		
		// Parse the data returned from Amazon.
		$new_items = array();
		
		// The basic SQL query.
		$base_insert_sql = 'INSERT INTO exp_bucketlist_items (
				bucket_id, item_extension, item_is_folder, item_name, item_path, item_size, site_id
			) VALUES (%s)';
		
		foreach ($s3_items AS $s3_item)
		{
			/**
			 * Every 1500 items, we write to the database. This prevents MySQL max_allowed_packet
			 * errors when dealing with extremely large buckets.
			 */
			
			if (count($new_items) >= 1500)
			{
				$DB->query(sprintf($base_insert_sql, implode('), (', $new_items)));
				$new_items = array();
			}
			
			if ($item = $this->_parse_item_s3_result($s3_item))
			{
				/**
				 * Paranoia with the string escaping, but better than the alternative. Field list:
				 * bucket_id, item_extension, item_is_folder, item_name, item_path, item_size, site_id
				 */
				
				$new_items[] = "'" .$DB->escape_str($bucket['bucket_id']) ."'"
					.", '" .$DB->escape_str($item['item_extension']) ."'"
					.", '" .$DB->escape_str($item['item_is_folder']) ."'"
					.", '" .$DB->escape_str($item['item_name']) ."'"
					.", '" .$DB->escape_str($item['item_path']) ."'"
					.", '" .$DB->escape_str($item['item_size']) ."'"
					.", {$this->_site_id}";
			}
		}
		
		// Stragglers?
		if (count($new_items) > 0)
		{
			$DB->query(sprintf($base_insert_sql, implode('), (', $new_items)));
		}
		
		return TRUE;
		
	}
	
	
	/**
	 * Checks whether the stored buckets still exist, and whether any new
	 * buckets have been created in the interim. Does not check the contents.
	 *
	 * @access	private
	 * @return 	bool
	 */
	private function _update_buckets_from_s3()
	{
		global $DB;
		
		// Retrieve all the buckets from Amazon.
		$s3 = new S3($this->site_settings['access_key_id'], $this->site_settings['secret_access_key'], FALSE);
		
		$s3_buckets = @$s3->listBuckets();
		
		// Do we have any results?
		if ( ! $s3_buckets)
		{
			return FALSE;
		}
		
		/**
		 * Delete any obsolete buckets, and their associated items.
		 */
		
		// @todo: data cleansing. Should never be an issue, but still...
		$valid_bucket_names = "'" .implode("', '", $s3_buckets) ."'";
		
		// Retrieve the obsolete buckets.
		$db_obsolete_buckets = $DB->query("SELECT bucket_id
			FROM exp_bucketlist_buckets
			WHERE bucket_name NOT IN({$valid_bucket_names})");
		
		if ($db_obsolete_buckets->num_rows > 0)
		{
			// Create an array of obsolete bucket IDs.
			$obsolete_bucket_ids = array();
			
			foreach ($db_obsolete_buckets->result AS $db_obb)
			{
				$obsolete_bucket_ids[] = $db_obb['bucket_id'];
			}
			
			$obsolete_bucket_ids = "'" .implode("', '", $obsolete_bucket_ids) ."'";
			
			// Delete the associated items.
			$DB->query("DELETE FROM exp_bucketlist_items
				WHERE bucket_id IN ({$obsolete_bucket_ids})");
			
			// Delete the obsolete buckets.
			$DB->query("DELETE FROM exp_bucketlist_buckets
				WHERE bucket_id IN ({$obsolete_bucket_ids})");
		}
		
		/**
		 * Add any missing buckets to the database.
		 */
		
		// Retrieve all the buckets from the database.
		$existing_buckets = $this->_load_all_buckets_from_db();
		
		/**
		 * We've deleted any obsolete buckets, so if the number
		 * of existing buckets equals the number of S3 buckets,
		 * we're golden.
		 */
		
		if (count($existing_buckets) != count($s3_buckets))
		{
			// Create an array of the existing bucket names.
			$existing_bucket_names = array();
			foreach ($existing_buckets AS $eb)
			{
				$existing_bucket_names[] = $eb['bucket_name'];
			}
			
			// Determine the missing items.
			$missing_bucket_names = array_diff($s3_buckets, $existing_bucket_names);
			$missing_buckets = array();
			
			$old_cache = strtotime('19 February 1973');		// Olden times.
			
			foreach ($missing_bucket_names AS $missing)
			{
				$missing_buckets[] = "{$this->_site_id}, '{$missing}', {$old_cache}";
			}
			
			// Build the SQL.
			$sql = "INSERT INTO exp_bucketlist_buckets (
					site_id, bucket_name, bucket_items_cache_date
				) VALUES (" .implode('), (', $missing_buckets) .")";
				
			$DB->query($sql);
		}
		
		return TRUE;
	}
	
	
	/**
	 * Forwards the specified file from the $_FILES array to S3
	 *
	 * @access	private
	 * @param	string		$field_id		The ID of the file field.
	 * @param	string		$bucket_name	The name of the destination bucket.
	 * @param 	string		$item_path 		The path to the item from the bucket root.
	 * @return	bool
	 */
	private function _upload_file_to_s3($field_id = '', $bucket_name = '', $item_path = '')
	{
		global $EXT;
		
		$this->_uploads = array();
		
		// Idiot check.
		if ( ! $field_id OR ! isset($_FILES[$field_id]) OR ! $bucket_name)
		{
			return FALSE;
		}
		
		// If we're in demonstration mode, just return TRUE.
		if ($this->_demo)
		{
			return TRUE;
		}
		
		/**
		 * Build the upload data array. Note that we each item as an array so
		 * any hooks have the option of creating new files, based on this one, for
		 * upload. Thumbnail images is a good example of this in practise.
		 */
		
		$uploads = array(
			array(
				'bucket'	=> $bucket_name,
				'file'		=> $_FILES[$field_id],
				'path'		=> rtrim($item_path, '/')
			)
		);
		
		// Call the bucketlist_s3_upload_start hook.
		if ($EXT->active_hook('bucketlist_remote_upload_start') === TRUE)
		{
			$uploads = $EXT->call_extension('bucketlist_remote_upload_start', $uploads, $this->_member_data['member_id']);

			if ($EXT->end_script === TRUE)
			{
				return TRUE;
			}
		}
		
		// Retrieve the Amazon account credentials.
		$access_key = $this->site_settings['access_key_id'];
		$secret_key = $this->site_settings['secret_access_key'];
		
		// Create the S3 instance.
		$s3 = new S3($access_key, $secret_key, FALSE);
		
		// Loop through the uploads.
		$success = TRUE;
		
		foreach ($uploads AS $upload)
		{
			$upload['path'] = trim($upload['path'], '/');
			
			$uri = $upload['path']
				? $upload['path'] .'/' .$upload['file']['name']
				: $upload['file']['name'];
				
			// Generate the input array for our file.
			$input = $s3->inputFile($upload['file']['tmp_name']);
			
			// We don't stop if things go pear shaped.
			$success = $success && $s3->putObject($input, $upload['bucket'], $uri, S3::ACL_PUBLIC_READ);
		}
		
		$this->_uploads = $uploads;
		return $success;
	}
	
	
	/**
	 * Validates the structure of a 'bucket' array. Returns a valid item array, with any extraneous
	 * information stripped out, or FALSE.
	 *
	 * @access	private
	 * @param 	array 			$bucket		The bucket to validate.
	 * @return 	array|bool
	 */
	private function _validate_bucket($bucket = array())
	{
		if ( ! $bucket OR ! is_array($bucket))
		{
			return FALSE;
		}
		
		// Do we have the required information?
		$required_fields 	= array('bucket_id', 'bucket_items_cache_date', 'bucket_name', 'site_id');
		$valid_bucket 		= array();
		$missing_field		= FALSE;
		
		foreach ($required_fields AS $field_id)
		{
			if ( ! array_key_exists($field_id, $bucket))
			{
				$missing_field = TRUE;
				break;
			}
			
			$valid_bucket[$field_id] = $bucket[$field_id];
		}
		
		return ($missing_field ? FALSE : $valid_bucket);
	}
	
	
	/**
	 * Validates the structure of an 'item' array. Returns a valid item array, with any extraneous
	 * information stripped out, or FALSE.
	 *
	 * @access	private
	 * @param 	array 			$item		The item to validate.
	 * @return 	array|bool
	 */
	private function _validate_item($item = array())
	{
		if ( ! $item OR ! is_array($item))
		{
			return FALSE;
		}
		
		$default_item = array(
			'item_extension'	=> '',
			'item_is_folder'	=> '',
			'item_name'			=> '',
			'item_path'			=> '',
			'item_size'			=> ''
		);
		
		$item = array_merge($default_item, $item);
		
		/**
		 * Item extension is optional in this first check, as folders don't have one.
		 */
		
		$required_fields 	= array('item_is_folder', 'item_name', 'item_path', 'item_size');
		$valid_item 		= array();
		$missing_field		= FALSE;
		
		foreach ($required_fields AS $field_id)
		{
			if ($field_id != 'item_size' && ( ! is_string($item[$field_id]) OR $item[$field_id] == ''))
			{
				$missing_field = TRUE;
				break;
			}
			
			$valid_item[$field_id] = $item[$field_id];
		}
		
		// One last check. Files need extension.
		if (strtolower($valid_item['item_is_folder']) != 'y' && ( ! is_string($item['item_extension']) OR $item['item_extension'] == ''))
		{
			$missing_field = TRUE;
		}
		else
		{
			$valid_item['item_extension'] = strtolower($item['item_extension']);
		}
		
		if ($missing_field)
		{
			return FALSE;
		}
		else
		{
			// Bit of cleaning up, and we'll be done.
			$valid_item['item_is_folder'] 	= strtolower($valid_item['item_is_folder']);
			$valid_item['item_size']		= intval($valid_item['item_size']);
			
			return $valid_item;
		}
	}
	
	
	
	/**
	 * ----------------------------------------------------------------
	 * PUBLIC METHODS
	 * ----------------------------------------------------------------
	 */
	
	/**
	 * Constructor function.
	 *
	 * @access	public
	 */
	public function __construct()
	{
		global $DB, $IN, $PREFS;
		
		$this->_site_id 	= $DB->escape_str($PREFS->ini('site_id'));
		$this->_class 		= get_class($this);
		$this->_lower_class = strtolower($this->_class);
		$this->_namespace	= 'sl';
		$this->_has_matrix_2 = $DB->table_exists('exp_matrix_cols');
		
		/**
		 * If this is a beta version, force the update method to run
		 * on the FieldFrame settings page.
		 */
		
		if (strpos($this->info['version'], 'b')
			&& $IN->GBL('P', 'GET') == 'extension_settings'
			&& $IN->GBL('name', 'GET') == 'fieldframe')
		{
			$this->_force_update();
		}
		
	}
	
	
	/**
	 * Displays the fieldtype in an FF Matrix.
	 *
	 * @access	public
	 * @param	string		$cell_name			The cell ID.
	 * @param	string		$cell_data			Previously saved cell data.
	 * @param	array		$cell_settings		The cell settings.
	 * @return	string
	 */
	public function display_cell($cell_name, $cell_data, $cell_settings)
	{
		return $this->display_field($cell_name, $cell_data, $cell_settings);
	}
	
	
	/**
	 * Adds custom FF Matrix cell settings.
	 *
	 * @access	public
	 * @param	array		$cell_settings		Previously saved cell settings.
	 * @return	string
	 */
	public function display_cell_settings($cell_settings = array())
	{
		$settings = $this->display_field_settings($cell_settings, TRUE);
		return isset($settings['rows']) ? $settings['rows'][0][0] : '';
	}
	
	
	/**
	 * Displays the field.
	 *
	 * @access	public
	 * @param	string		$field_name			The field ID.
	 * @param	string		$field_data			Previously-saved field data.
	 * @param 	array 		$field_settings		The field settings.
	 * @return	string
	 */
	public function display_field($field_name, $field_data, $field_settings)
	{
		global $IN, $LANG, $SESS;
		
		// We have no truck with AJAX requests. They are handled by sessions_start.
		if ($IN->GBL('ajax', 'POST') == 'y')
		{
			return '';
		}
		
		// Set the class variables.
		$this->_saved_field_settings = $field_settings;
		$this->_member_data = $SESS->userdata;
		
		// Retrieve the correct language file.
		$LANG->fetch_language_file($this->_lower_class);
		
		/**
		 * Include CSS and JS.
		 *
		 * NOTE: Do not use a timestamp here. It causes the CSS & JS to be loaded twice.
		 */
		
		$this->include_js('js/cp.js?' .$this->info['version']);
		$this->include_js('js/jquery.bucketlist.js?' .$this->info['version']);
		$this->include_css('css/cp.css?' .$this->info['version']);
		
		// Define some language strings for use in the JS.
		$upload_failure = str_replace(array('"', '"'), '', $LANG->line('upload_failure'));
		$confirm_exit	= addslashes($LANG->line('confirm_exit'));
		
		$js_language = "var languageStrings = {
			uploadFailureGeneric : '{$upload_failure}',
			confirmExit : '{$confirm_exit}'
		};";
		
		$this->insert_js($js_language);
		
		
		/**
		 * Now on with the real work.
		 */
		
		// Open the wrapper element.
		$html = '<div class="bl-wrapper">';
		
		// Can't do much without the S3 credentials.
		if ( ! $this->_check_s3_credentials())
		{
			$html .= '<p class="bl-alert">' .$LANG->line('missing_credentials'). '</p>';
		}
		else
		{
			// If we have a saved field, does it still exist on the server?
			if ( ! $this->_item_exists_on_s3($field_data))
			{
				// Let's not try to get too clever here.
				$field_data = '';
			}
			
			// Update everything from S3, if required. Only need to do this once.
			if ( ! $SESS->cache[$this->_namespace][$this->_lower_class]['updated_from_s3'])
			{
				$this->_update_buckets_from_s3();
				
				$buckets = $this->_load_all_buckets_from_db();
				foreach ($buckets AS $bucket)
				{
					$this->_update_bucket_items_from_s3($bucket['bucket_name']);
				}
				
				$SESS->cache[$this->_namespace][$this->_lower_class]['updated_from_s3'] = TRUE;
			}

			// Retrieve the tree root UI (i.e. the buckets).
			$html .= $this->_build_root_ui($field_settings);
			
			// Output a hidden field containing the field's value.
			$html .= '<input class="bl-hidden" id="' .$field_name .'" name="' .$field_name
				.'" type="hidden" value="' .rawurlencode($field_data) .'" />';
		
		}
		
		// Close the wrapper element.
		$html .= '</div><!-- /.bucketlist -->';
		
		return $html;
		
	}
	
	
	/**
	 * Adds custom settings to the "Edit field" form.
	 *
	 * @access	public
	 * @param	array 		$field_settings		Previously saved field settings.
	 * @param 	bool 		$is_cell			Is this being called from the display_cell_settings method?
	 * @return	array
	 */
	public function display_field_settings($field_settings = array(), $is_cell = FALSE)
	{
		global $DB, $LANG, $SESS;
		
		// Set the class variables.
		$this->_saved_field_settings = $field_settings;
		$this->_member_data = $SESS->userdata;
		
		// Include the necessary JavaScript and CSS.
		$this->include_js('js/cp.js?' .$this->info['version']);
		$this->include_js('js/jquery.bucketlist.js?' .$this->info['version']);
		$this->include_css('css/cp.css?' .$this->info['version']);
		
		$wrapper_class = 'bl-settings';
		$wrapper_class .= $is_cell
			? $this->_has_matrix_2 ? ' bl-matrix-settings' : ' bl-ff-matrix-settings'
			: '';
		
		$html = '<div class="' .$wrapper_class .'">';
		
		// Update the buckets cache from S3.
		$this->_update_buckets_from_s3();
		
		// Load the buckets from the database.
		$buckets = $this->_load_all_buckets_from_db();
		
		// Load the member groups from the database.
		$member_groups = $this->_load_admin_member_groups();
		
		// We need buckets and member groups to continue.
		if ( ! $buckets OR ! $member_groups)
		{
			$html .= '<p>' .$LANG->line('no_buckets') .'</p>';
		}
		else
		{
			// Path to images.
			$image_path = FT_URL .$this->_lower_class. '/img/icons/';
			
			// BucketList configuration.
			$html .= '<div class="bl-instructions">';			
			$html .= '<h3>' .$LANG->line('instructions_title') .'</h3>';
			$html .= '<p>' .$LANG->line('instructions_preamble') .'</p>';
			
			$html .= '<div>';
			$html .= '<p><a href="#" title="' .$LANG->line('instructions_link') .'">' .$LANG->line('instructions_link') .'</a></p>';
			$html .= '<ul>';
			$html .= '<li><img src="' .$image_path .'setting-show.png" /> ' .$LANG->line('instructions_key_show') .'</li>';
			$html .= '<li><img src="' .$image_path .'setting-upload.png" /> ' .$LANG->line('instructions_key_upload') .'</li>';
			$html .= '<li><img src="' .$image_path .'setting-all-files.png" /> ' .$LANG->line('instructions_key_all_files') .'</li>';
			$html .= '</ul>';
			$html .= '</div>';
			$html .= '</div><!-- /.bl-instructions -->';
			
			$html .= '<table cellpadding="0" cellspacing="0">';
			$html .= '<thead>';
			$html .= '<tr>';
			$html .= "<th>{$LANG->line('member_group')}</th>";
			$html .= "<th>{$LANG->line('settings')}</th>";
			$html .= '</tr>';
			$html .= '</thead>';
			$html .= '<tbody>';
			
			$row_count = 0;
			$path_count = 0;
			
			// Settings are now assigned on a member group basis.
			foreach ($member_groups AS $member_group)
			{
				$group_id = $member_group['group_id'];
				$group_title = $member_group['group_title'];
				$group_settings = $this->_get_group_field_settings($group_id);
				
				// Open member group row.
				$html .= '<tr class="' .($row_count++ % 2 ? 'even' : 'odd') .'">';
				$html .= "<td>{$group_title}</td>";
				
				// Settings.
				$html .= '<td style="padding : 0"><div class="bl-wrapper">';
				$html .= '<ul>';
				
				foreach ($buckets AS $bucket)
				{
					$path_settings = array_key_exists($bucket['bucket_name'], $group_settings['paths'])
						? $group_settings['paths'][$bucket['bucket_name']]
						: $this->_get_default_path_settings();
					
					$html .= '<li class="bl-directory'
						.($path_settings['show'] != 'y' ? ' bl-disabled' : '')
						.'">';
					
					// UI.
					$html .= '<span title="' .$bucket['bucket_name'] .'">' .$bucket['bucket_name'] .'</span>';
					
					$html .= '<a class="bl-toggle-show'
						.($path_settings['show'] != 'y' ? ' bl-disabled' : '')
						.'" href="#" title="' .$LANG->line('toggle_show') .'">'
						.$LANG->line('toggle_show') .'</a>';
						
					$html .= '<a class="bl-toggle-upload'
						.($path_settings['allow_upload'] != 'y' ? ' bl-disabled' : '')
						.'" href="#" title="' .$LANG->line('toggle_upload') .'">'
						.$LANG->line('toggle_upload') .'</a>';
						
					$html .= '<a class="bl-toggle-all-files'
						.($path_settings['all_files'] != 'y' ? ' bl-disabled' : '')
						.'" href="#" title="' .$LANG->line('toggle_all_files') .'">'
						.$LANG->line('toggle_all_files') .'</a>';
					
					// Hidden fields.
					$html .= '<input
						name="member_groups[' .$group_id .'][paths][' .$path_count .'][path]"
						type="hidden" value="' .$bucket['bucket_name'] .'" />';
					
					$html .= '<input
						name="member_groups[' .$group_id .'][paths][' .$path_count .'][show]"
						type="hidden" value="' .$path_settings['show'] .'" />';
						
					$html .= '<input
						name="member_groups[' .$group_id .'][paths][' .$path_count .'][allow_upload]"
						type="hidden" value="' .$path_settings['allow_upload'] .'" />';
						
					$html .= '<input
						name="member_groups[' .$group_id .'][paths][' .$path_count .'][all_files]"
						type="hidden" value="' .$path_settings['all_files'] .'" />';
					
					$html .= '</li>';
					$path_count++;
				}
				
				$html .= '</ul>';
				$html .= '</div></td>';
				
				// Close member group row.
				$html .= '</tr>';
			}
			
			$html .= '</tbody>';
			$html .= '</table>';
		}
		
		$html .= '</div>';
		
		return array(
			'rows' => array(array($html))
		);
	}
	
	
	/**
	 * Displays the site-wide fieldtype settings form.
	 *
	 * @access	public
	 * @return	string
	 */
	public function display_site_settings()
	{
		// Initialise a new instance of the SettingsDisplay class.
		$sd = new Fieldframe_SettingsDisplay();
		
		// Open the settings block.
		$ret = $sd->block('site_settings_heading');
		
		// Retrieve the settings.
		$settings = array_merge($this->default_site_settings, $this->site_settings);
			
		// Create the settings fields.
		$ret .= $sd->row(array(
			$sd->label('access_key_id'),
			$sd->text('access_key_id', $settings['access_key_id'])
			));
			
		$ret .= $sd->row(array(
			$sd->label('secret_access_key'),
			$sd->text('secret_access_key', $settings['secret_access_key'])
			));
		
		/*	
		$options = array(
			'y' => 'yes',
			'n' => 'no'
			);
			
		$ret .= $sd->row(array(
			$sd->label('use_ssl'),
			$sd->select('use_ssl', $use_ssl, $options)
			));
		*/
			
		$options = array(
			'300'	=> '5_min',
			'600'	=> '10_min',
			'900'	=> '15_min',
			'1800'	=> '30_min',
			'2700'	=> '45_min',
			'3600'	=> '60_min',
			'5400'	=> '90_min',
			'7200' 	=> '120_min',
			'14400' => '240_min',
			'21600' => '360_min',
			'28800' => '480_min'
		);
			
		$ret .= $sd->row(array(
			$sd->label('cache_duration', 'cache_duration_hint'),
			$sd->select('cache_duration', $settings['cache_duration'], $options)
		));
		
		$options = array(
			'y' => 'yes',
			'n' => 'no'
		);
			
		$ret .= $sd->row(array(
			$sd->label('custom_url', 'custom_url_hint'),
			$sd->select('custom_url', $settings['custom_url'], $options)
		));
			
		// Close the settings block.
		$ret .= $sd->block_c();
		
		// Return the settings block.
		return $ret;
	}
	
	
	/**
	 * Outputs the basic file information (the URL to the file).
	 *
	 * @access	public
	 * @param	array		$params				Array of tag parameters as key / value pairs.
	 * @param	string		$tagdata			Content between the opening and closing tags (not used).
	 * @param	string		$field_data			The field data.
	 * @param	array		$field_settings		The field settings.
	 * @return	string
	 */
	public function display_tag($params, $tagdata, $field_data, $field_settings)
	{
		$out = '';
		$bucket_and_path = $this->_split_bucket_and_path_string($field_data);
			
		if ($bucket_and_path['bucket'] && $bucket_and_path['item_path'])
		{
			$out .= $this->site_settings['use_ssl'] == 'y' ? 'https://' : 'http://';
			$out .= urlencode($bucket_and_path['bucket'])
				.($this->site_settings['custom_url'] == 'y' ? '/' : '.s3.amazonaws.com/') .urlencode($bucket_and_path['item_path']);
		}
		
		return $out;
	}
	
	
	/**
	 * Outputs the file extension.
	 *
	 * @access	public
	 * @param	array		$params				Array of tag parameters as key / value pairs.
	 * @param	string		$tagdata			Content between the opening and closing tags (not used).
	 * @param	string		$field_data			The field data.
	 * @param	array		$field_settings		The field settings.
	 * @return	string
	 */
	public function file_extension($params, $tagdata, $field_data, $field_settings)
	{
		$out = '';
		
		if ($item = $this->_load_item_using_field_data($field_data))
		{
			$out = $item['item_extension'];
		}
		
		return $out;
	}
	
	
	/**
	 * Outputs the file name.
	 *
	 * @access	public
	 * @param	array		$params				Array of tag parameters as key / value pairs.
	 * @param	string		$tagdata			Content between the opening and closing tags (not used).
	 * @param	string		$field_data			The field data.
	 * @param	array		$field_settings		The field settings.
	 * @return	string
	 */
	public function file_name($params, $tagdata, $field_data, $field_settings)
	{
		$out = '';
		
		if ($item = $this->_load_item_using_field_data($field_data))
		{
			$out = $item['item_name'];
		}
		
		return $out;
	}
	
	
	/**
	 * Outputs the file size.
	 *
	 * @access	public
	 * @param	array		$params				Array of tag parameters as key / value pairs.
	 * @param	string		$tagdata			Content between the opening and closing tags (not used).
	 * @param	string		$field_data			The field data.
	 * @param	array		$field_settings		The field settings.
	 * @return	string
	 */
	public function file_size($params, $tagdata, $field_data, $field_settings)
	{
		// Default parameters.
		$params = array_merge(array('format' => 'auto'), $params);
		
		$out = '';
		
		if ($item = $this->_load_item_using_field_data($field_data))
		{
			switch ($params['format'])
			{
				case 'bytes':
					$out = $item['item_size'];
					break;
				
				case 'kilobytes':
					$out = round($item['item_size'] / 1024, 2);
					break;
				
				case 'megabytes':
					$out = round($item['item_size'] / 1048576, 2);		// 1024 * 1024
					break;
				
				case 'auto':
				default:
					if ($item['item_size'] < 1048567)
					{
						$out = round($item['item_size'] / 1024, 2) .'<abbr title="Kilobytes">KB</abbr>';
					}
					else
					{
						$out = round($item['item_size'] / 1048567, 2) .'<abbr title="Megabytes">MB</abbr>';
					}
					break;
			}
		}
		
		return $out;
	}
	
	
	/**
	 * Modifies the cell's POST data before it's saved to the database.
	 *
	 * @access	public
	 * @param	string		$cell_data			The POST data.
	 * @param	array		$cell_settings		The cell settings.
	 * @param 	mixed		$entry_id			Not used.
	 * @return	string
	 */
	public function save_cell($cell_data = '', $cell_settings = array(), $entry_id = '')
	{
		return $this->save_field($cell_data, $cell_settings, $entry_id);
	}
	
	
	/**
	 * Modifies the field's POST data before it's saved to the database.
	 *
	 * @access	public
	 * @param	string		$field_data			The POST data.
	 * @param	array		$field_settings		The field settings.
	 * @param 	mixed		$entry_id			Not used.
	 * @return	string
	 */
	public function save_field($field_data = '', $field_settings = array(), $entry_id = '')
	{
		return rawurldecode($field_data);
	}
	
	
	/**
	 * Handles AJAX requests.
	 *
	 * @access		public
	 * @param 		object		$session	The current Session object.
	 * @return		void
	 */
	public function sessions_start(&$session)
	{
		global $IN, $SESS;
		
		// Initialise the cache.
		if ( ! array_key_exists($this->_namespace, $session->cache))
		{
			$session->cache[$this->_namespace] = array();
		}
		
		$session->cache[$this->_namespace][$this->_lower_class] = array();
		$session->cache[$this->_namespace][$this->_lower_class]['updated_from_s3'] = FALSE;
		
		/**
		 * @since: 1.2.0
		 * For some reason, there are time when the $SESS object doesn't exist here.
		 * This only appears to affect FieldFrame 1.4, although I'm not 100% sure that's
		 * where the problem lies.
		 *
		 * This seems to be a reasonable workaround, for the time being.
		 */
		
		if ( ! isset($SESS))
		{
			$SESS = $session;
		}
		
		
		if ($IN->GBL('ajax', 'POST') == 'y' && $IN->GBL('addon_id', 'POST') == $this->_lower_class)
		{
			// Set the class variables.
			$this->_saved_field_settings = $this->_load_field_settings($IN->GBL('field_id', 'POST'));
			$this->_member_data = $this->_load_member_data();
			
			// We're either being summoned by the file tree, or the uploader. Which is it?
			$request = $IN->GBL('request', 'POST');
			
			switch ($request)
			{
				case 'tree':
					$branch_html = $this->_build_branch_ui(urldecode($IN->GBL('dir', 'POST')), $IN->GBL('field_id', 'POST'));
					$this->_output_ajax_response($branch_html);
					break;
					
				case 'upload':
					$this->_process_upload();
					break;
					
				default:
					// No idea.
					break;
			}
		}
	}
	
	
	/**
	 * Performs house-keeping when upgrading from an earlier version.
	 *
	 * @access	public
	 * @param	string|bool		$from		The previous version, or FALSE, if this is the initial installation.
	 */
	public function update($from = FALSE)
	{
		global $DB, $LANG, $REGX;
		
		/**
		 * @todo: move these inside the update conditional?
		 */
		
		$this->_force_update();
		$this->_update_buckets_from_s3();
		
		
		if ($from && $from < '1.2')
		{
			/**
			 * If we're upgrading from pre-1.1 we need an array of all the available buckets.
			 * We create this array regardless of the old version, for use as a fallback in
			 * the case of missing data.
			 */

			$buckets = $this->_load_all_buckets_from_db();
			$field_buckets = array();

			foreach ($buckets AS $bucket)
			{
				$field_buckets[] = $bucket['bucket_name'];
			}
			
			
			/**
			 * --------------------------------------------
			 * Update the BucketList fields.
			 * --------------------------------------------
			 */
			
			// Determine the BucketList fieldtype ID.
			$db_bucketlist_ft = $DB->query("SELECT fieldtype_id
				FROM exp_ff_fieldtypes
				WHERE class = '{$this->_lower_class}'
				LIMIT 1");

			if ($db_bucketlist_ft->num_rows === 1)
			{
				// Retrieve all the BucketList fields.
				$db_fields = $DB->query("SELECT field_id, ff_settings
					FROM exp_weblog_fields
					WHERE field_type = 'ftype_id_" .$db_bucketlist_ft->row['fieldtype_id'] ."'");
				
				
				foreach ($db_fields->result AS $db_field)
				{
					/**
					 * If this is pre-1.1, there are no settings, so we just make all
					 * the buckets available.
					 */
					
					$current_settings = $from > '1.1'
						? $this->_unserialize($db_field['ff_settings'])
						: array('available_buckets' => $field_buckets);
						
					$field_settings = $this->_build_update_settings($current_settings);
					
					$DB->query($DB->update_string(
						'exp_weblog_fields',
						array('ff_settings' => $this->_serialize($field_settings)),
						"field_id = '{$db_field['field_id']}'" 
					));
				}
			}
			
			
			/**
			 * --------------------------------------------
			 * Update the Matrix 2.x fields.
			 * --------------------------------------------
			 */
			
			if ($this->_has_matrix_2)
			{
				$db_matrix_cols = $DB->query("SELECT col_id, col_settings
					FROM exp_matrix_cols
					WHERE col_type = '{$this->_lower_class}'");
				
				if ($db_matrix_cols->num_rows > 0)
				{
					foreach ($db_matrix_cols->result AS $db_matrix_col)
					{
						/**
						 * If this is pre-1.1, there are no settings, so we just make all
						 * the buckets available.
						 */
						
						$current_settings = $from > '1.1'
							? $this->_unserialize($db_matrix_col['col_settings'], TRUE, TRUE)
							: array('available_buckets' => $field_buckets);
						
						$matrix_settings = $this->_build_update_settings($current_settings);
						
						/**
						 * If there are a lot of Matrices, with a lot of columns, we could be
						 * making a shitload of database calls here. On the other hand, this
						 * is a one-time operation, so let's not worry about performance unless
						 * it becomes an issue.
						 */
						
						$DB->query($DB->update_string(
							'exp_matrix_cols',
							array('col_settings' => $this->_serialize($matrix_settings, TRUE)),
							"col_id = '{$db_matrix_col['col_id']}'"
						));
					}
				}
			}
			
			
			/**
			 * --------------------------------------------
			 * Update the FF Matrix 1.x fields.
			 * --------------------------------------------
			 */
			
			if ( ! $this->_has_matrix_2)
			{
				// Determine the FF Matrix fieldtype ID.
				$db_matrix_ft = $DB->query("SELECT fieldtype_id
					FROM exp_ff_fieldtypes
					WHERE class = 'ff_matrix'
					LIMIT 1");
					
				// Retrieve all the FF Matrix fields.
				if ($db_matrix_ft->num_rows === 1)
				{
					$db_matrices = $DB->query("SELECT field_id, ff_settings
						FROM exp_weblog_fields
						WHERE field_type = 'ftype_id_" .$db_matrix_ft->row['fieldtype_id'] ."'");
					
					foreach ($db_matrices->result AS $db_matrix)
					{
						$matrix_settings = $this->_unserialize($db_matrix['ff_settings']);
						$update_matrix = FALSE;
						
						if ( ! isset($matrix_settings['cols']))
						{
							continue;
						}
						
						// Loop through the FF Matrix columns.
						foreach ($matrix_settings['cols'] AS $col_key => $col_val)
						{
							if (isset($col_val['type']) && $col_val['type'] == $this->_lower_class)
							{
								/**
								 * If this is pre-1.1, there are no settings, so we just make all
								 * the buckets available.
								 */

								$current_settings = ($from > '1.1' && isset($col_val['settings']))
									? $col_val['settings']
									: array('available_buckets' => $field_buckets);

								$matrix_settings['cols'][$col_key]['settings'] = $this->_build_update_settings($current_settings);
								$update_matrix = TRUE;
							}
						}

						if ($update_matrix)
						{
							$DB->query($DB->update_string(
								'exp_weblog_fields',
								array('ff_settings' => $this->_serialize($matrix_settings)),
								"field_id = '{$db_matrix['field_id']}'"
							));
						}
						
					} /* End of $db_matrices->result loop */
				}
			} /* End of FF Matrix 1.x upgrade script */
			
		} /* End of $from conditional */
		
	} /* End of $this->update */

}

/* End of file	: ft.bucketlist.php */
/* Location		: /system/extensions/fieldtypes/bucketlist/ft.bucketlist.php */
