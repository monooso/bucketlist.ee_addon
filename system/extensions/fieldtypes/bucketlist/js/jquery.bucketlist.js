/**
 * BucketList JavaScript. Handles the creation and display of the file
 * tree, and uploading.
 *
 * Original inspiration for the file tree code came from a jQuery plugin
 * by Cory S.N. LaViska (http://abeautifulsite.net).
 *
 * @package		BucketList
 * @author 		Stephen Lewis (http://eepro.co.uk/)
 * @copyright 	Copyright (c) 2009, Stephen Lewis
 * @link 		http://eepro.co.uk/bucketlist/
 */

(function($) {

$.fn.bucketlist = function(options) {
	
	// Build main options.
	var globalOptions = $.extend({}, $.fn.bucketlist.defaults, options);
	
	// Iterate through each element.
	return this.each(function() {
		
		// Convenience variable.
		var $this = $(this);
		
		// Element-specific options.
		var localOptions = $.meta ? $.extend({}, globalOptions, $this.data()) : globalOptions;
		
		// In-progress uploads.
		var uploads = {};
		
		// Flag to hide the initial loading message.
		var initialLoad = true;
		
		// In case this ever changes.
		var slash = '%2F';
		
		/**
		 * Retrieve the initialFile, if it has been supplied. Note that
		 * it will be rawurlencoded. We don't decode it (see showTree
		 * for a rant on this), instead we split it using the encoded
		 * slash.
		 */
		
		if (localOptions.initialFile != '') {
			var initialFilePath = localOptions.initialFile.split(slash);
			var initialFileStep = 0;
		}
		
		
		
		/**
		 * ----------------------------------------------------------
		 * GENERAL FUNCTIONS
		 * ----------------------------------------------------------
		 */
		
		/**
		 * Retrieves the specified item from the languageStrings object.
		 * If the item does not exist, the supplied ID is returned.
		 *
		 * @access	private
		 * @param	string		id		The language string ID.
		 */
		function getLanguageString(id) {
			return (languageStrings['id'] == 'undefined') ? id : languageString['id'];
		}; /* getLanguageString */
		
		
		/**
		 * Are we dealing with IE7? Tried setting this as a this.isIE7 variable,
		 * and IE7 failed to recognise it. Oh the irony.
		 *
		 * @access	private
		 */
		function isIE7() {
			return (document.all && navigator.appVersion.indexOf('MSIE 7.') != -1);
		}
		
		
		/**
		 * Fixes *other* IE7 FF Matrix fields on the page. Not entirely happy
		 * that the code belongs here, but here's the deal...
		 *
		 * When any non-FF Matrix BucketList tree is clicked, all the FF Matrix
		 * bucket trees disappear in IE7. *punches Bill Gates in the kidneys*
		 *
		 * The same thing happens, sometimes, on page load.
		 *
		 * The fix is to resize the FF Matrix table(s) using a simple bit of JS.
		 * I'm not entirely happy that this fix belongs here, but never mind.
		 * This is the simplest solution for the time being.
		 *
		 * @access	private
		 */
		function fix_ie7_matrices() {
			
			if (isIE7()) {
				
				$('td.bucketlist').each(function() {
					$this = $(this);
					
					tWidth = $this.width();
					$this.width(tWidth - 1);
					$this.width(tWidth);
				});
				
			}
		};
		
		
		
		/**
		 * ----------------------------------------------------------
		 * UPLOADING FUNCTIONS
		 * ----------------------------------------------------------
		 */
		
		/**
		 * Initialises the 'upload' link in the specified branch. Wraps it
		 * in a div, and creates a new form.
		 *
		 * @access	private
		 * @param 	object		$root			A jQuery object containing the root of this branch.
		 * @param 	string 		bucketName		The name of the current bucket.
		 * @param	string		filePath		The path to the current folder.
		 * @return 	void
		 */
		function initializeUpload($root, path) {
			
			var $uploadLink = $root.find('.upload a');
			
			if ($uploadLink.length == 0) {
				// Nothing more we can do here.
				return false;
			}

			// Create the element wrapper, and append the file element.
			$uploadLink.wrap('<div class="bucketload"></div>');
			
			// Create the form.
			var formDecl = '<form accept-encoding="utf-8" action="' + localOptions.uploadFormAction + '"\
				enctype="multipart/form-data" method="post"></form>';
			var $form = $(formDecl);
			
			// Add the file field.
			$('<input name="file" type="file">').appendTo($form).bind('change', fileChange);
			
			// Add the hidden 'path' field.
			$form.append('<input id="path" name="path" type="hidden" value="' + path + '">');
			
			// Apend the form to the div.
			$uploadLink.parent().append($form);	
			
			// If this is an anchor (which it should be), disable the default click event.
			if ($uploadLink[0].nodeName.toLowerCase() == 'a') {
				$uploadLink.bind('click', function(e) {
					return false;
				});
			}

			/**
			 * Sneakiness, to keep the file element's "Browse" button underneath the mouse
			 * pointer whenever the user mouses-over the .bucketload container.
			 *
			 * The ridiculous hoops we still have to jump through to get anything halfway
			 * decent on the web make me weep.
			 */

			$uploadLink.parent().bind('mousemove', function(e) {

				var $file	= $(this).find('input[type="file"]');

				var offset	= $(this).offset();
				var fileX	= e.pageX - offset.left - ($file.width() - 30);
				var fileY	= e.pageY - offset.top - ($file.height() / 2);

				$file.css('left', fileX);
				$file.css('top', fileY);

			});

		}; /* initializeUpload */
		
		
		/**
		 * Handles the Amazon response.
		 *
		 * @access	private
		 * @param 	object		e		The jQuery event object.
		 * @return 	void
		 */
		function amazonResponse(e) {
			
			// Shorthand.
			var $iframe = $(e.target);

			/**
			 * There is much sneakiness afoot. I've heard talk around t'interwebs
			 * of dynamically generated iframes being re-submitted on page reload.
			 *
			 * Quite how or why this would happen is beyond me, and I've been unable
			 * to reproduce this fabled condition. However, the fix is simple enough,
			 * so I've included it regardless.
			 *
			 * First run through, we set the content of the iframe to "javascript: false;".
			 * This of course triggers the onChange event, which runs this method again.
			 *
			 * Second time around, we unbind the 'load' listener (very important), and
			 * delete the iframe.
			 *
			 * Bit of a faff, but nothing too horrendous.
			 */

			// This is round 2.
			if (e.target.src.indexOf('javascript:') == 0) {
				$iframe.unbind('load');
				
				// Fix "busy" state in FF.
				setTimeout(function() {
					$iframe.remove();
				}, 0);

				return;
			}

			var $status		= $iframe.contents().find('#status');
			var $message	= $iframe.contents().find('#message');
			var $uploadId	= $iframe.contents().find('#uploadId');
			var $listItem	= $iframe.contents().find('#listItem');
			
			var status		= $status.length == 1 	? $status.text() 	: '';
			var message		= $message.length == 1 	? $message.text() 	: '';
			var uploadId	= $uploadId.length == 1 ? $uploadId.text() 	: '';
			var listItem	= $listItem.length== 1 	? $listItem.html() 	: '';

			var params = {
				listItem	: listItem,
				message		: message,
				status		: status,
				uploadId	: uploadId
			}

			// Do we have the expected information?
			if (status == '' || message == '' || uploadId == '') {

				/**
				 * We assume the worst. A blank message is passed, so we don't
				 * have to hard-code the language string here.
				 */

				params['listItem']	= '';
				params['message']	= '';
				params['status']	= 'failure';

				localOptions.onUploadFailure(params);

			} else {

				// Summon the handlers.
				(status == 'success')
					? localOptions.onUploadSuccess(params)
					: localOptions.onUploadFailure(params);
				
				/**
				 * Do we have a list item, and a branch? Some uploaded files will
				 * be duplicates. At the moment we just ignore those, although a
				 * visual indication that the file has been 'replaced' would be
				 * nice.
				 */
				
				if (uploads[uploadId] != 'undefined' && listItem != '') {
					
					// Get the branch root.
					var $branchRoot = uploads[uploadId];
					
					// Create the (orphan) list item.
					var $listItem = $(listItem).hide();
					
					// What is the list item's file name?
					var listItemFileName = $listItem.find('a').text().toLowerCase();
					
					/**
					 * Determine the point at which to insert the new item (alphabetically).
					 * Admitted defeat after trying to achieve this with $.map. May return
					 * to it, due to unhealthy stubborness.
					 */
					
					// Does the branch have any files at all?
					if ($branchRoot.children('.file').length == 0) {
						$listItem.appendTo($branchRoot);
						
					} else {
						
						var $successor = false;
						
						$branchRoot.find('> .file a').each(function(index) {
							if ($(this).text().toLowerCase() > listItemFileName) {
								$successor = $(this);
								return false;		// Stop the loop.
							}
						});

						if ($successor != false) {
							$listItem.insertBefore($successor);
						} else {
							$listItem.appendTo($branchRoot);
						}
					}
					
					// Insert the item, and animate its arrival.
					$listItem.slideDown(350);
				}
				
				/**
				 * Even if no list item was passed, still delete the upload item,
				 * if it exists.
				 */
				
				if (uploads[uploadId] != 'undefined')
				{
					delete uploads[uploadId];
				}
			}
			
			/**
			 * Originally we were setting this to "javascript: '<html></html>';"
			 * but that crashes Safari when the Web Inspector is open. Seriously.
			 */

			e.target.src = "javascript: false;";
			
		}; /* amazonResponse */
		
		
		/**
		 * Handles the file 'change' event. This is where the rubber hits the road.
		 *
		 * @access	private
		 * @param	object		e		The jQuery event object.
		 * @return 	void
		 */
		function fileChange(e) {
			
			// Some shortcuts.
			var $file = $(e.target);
			var $form = $file.parent('form');
			
			// Generate a new upload ID.
			var uploadId = Math.round(Math.random() * new Date().getTime());
			
			/**
			 * Add some additional hidden form fields.
			 * We add these at the last possible moment, and then delete them
			 * as soon as possible.
			 *
			 * Otherwise we run into problems with the 'publish' form submission
			 * being interpreted as a file upload request in IE.
			 */
			
			$form.append('<input id="addon_id" name="addon_id" type="hidden" value="bucketlist">');
			$form.append('<input id="ajax" name="ajax" type="hidden" value="y">');
			$form.append('<input id="request" name="request" type="hidden" value="upload">');
			$form.append('<input id="upload_id" name="upload_id" type="hidden" value="' + uploadId + '">');
			
			// Create a new iframe for the upload.
			var iframeId = 'bucketload-iframe-' + uploadId;
			var $iframe = $('<iframe id="' + iframeId + '" name="' + iframeId + '"></iframe>').appendTo('body').hide();
			
						
			// Wait a moment for the iframe to be created in FF and IE.
			setTimeout(function() {
				// Add the target attribute to the form, and submit it.
				$form.attr('target', iframeId).submit();
				
				// Add a callback handler to the iframe.
				$iframe.bind('load', amazonResponse);
				
				// Make a note of the uploadId, and its location.
				uploads[uploadId] = $form.closest('ul.bucketlist-tree');

				// Call the onStart handler.
				localOptions.onUploadStart({fileName : $file.val(), uploadId : uploadId});
				
				// Delete the hidden form fields to keep IE happy.
				$form.find('#addon_id').remove();
				$form.find('#ajax').remove();
				$form.find('#request').remove();
				$form.find('#upload_id').remove();
				
			}, 1);
			
		}; /* fileChange */
		
		
		
		/**
		 * ----------------------------------------------------------
		 * FILE TREE FUNCTIONS
		 * ----------------------------------------------------------
		 */
		
		/**
		 * Initialises the file tree.
		 *
		 * @access	private
		 */
		function initializeTree() {
			// Bind the click event to all BucketList links.
			$('li a', $this).bind('click', function(e) {
				treeClick($(e.target));
				return false;
			});
			
			// Are we auto-displaying an initial file?
			if ($.isArray(initialFilePath) && initialFilePath) {
					
				pathToLoad = initialFilePath[0] + slash;
				treeClick($this.find('[rel="' + pathToLoad + '"]'));
				
				initialFileStep++;
			}
			
		}; /* initializeTree */
		
		
		/**
		 * Loads a directory's sub-tree, or calls the callback handler when an
		 * item in the file tree is clicked.
		 *
		 * @access	private
		 * @param	jQuery object	$target		The click target.
		 */
		function treeClick($target) {
			
			fix_ie7_matrices();		// Wait for the slow child.
			
			if ($target.parent().hasClass('directory')) {

				if ($target.parent().hasClass('collapsed')) {

					/**
					 * Expand the tree. Only one branch of the tree can be
					 * open at any one time.
					 */
					
					if (isIE7()) {
						$target.parent().parent().find('ul').hide();
						fix_ie7_matrices();
					} else {
						$target.parent().parent().find('ul').slideUp({duration : 500});
					}
					
					$target.parent().parent().find('.directory').removeClass('expanded').addClass('collapsed');
					$target.parent().find('ul').remove();
					
					showTree({
						$root 	: $target.parent(),
						path	: $target.eq(0).attr('rel')
					});

					$target.parent().removeClass('collapsed').addClass('expanded');

				} else {
					// Collapse the tree.
					if (isIE7()) {
						$target.parent().parent().find('ul').hide();
						fix_ie7_matrices();
					} else {
						$target.parent().find('ul').slideUp({duration : 500});
					}
					
					$target.parent().removeClass('expanded').addClass('collapsed');
				}

			} else {
				
				// Is this item already selected?
				if ($target.parent().hasClass('selected')) {
					
					// Deselect the target element.
					$target.parent().removeClass('selected');
					
					// Clear the target value.
					field_value = '';
					
				} else {
					
					// Deselect and other selected elements.
					$target
						.closest('.eepro-co-uk')
						.find('li.selected')
						.removeClass('selected');
						
					// Select the target element.
					$target.parent().addClass('selected');
					
					// Record the target value.
					field_value = $target.attr('rel');
					
				}
				
				// Update the hidden form field.
				$target.closest('.eepro-co-uk').find('> input:hidden').val(field_value);
				
				// Callback.
				localOptions.onFileClick({$target : $target, fileName : $target.attr('rel')});
			}
			
		}; /* treeClick */


		/**
		 * Expand the tree when a 'directory' element is clicked.
		 *
		 * @access	private
		 * @param	params		object		Switched to an associative array, because it's
		 *									much easier when debugging the calling script.
		 * 									- $root	: jQuery object containing branch root.
		 *									- path	: file path string, including bucket.
		 */
		function showTree(params) {
			
			var localParams = $.extend({
				$root	: false,
				path	: ''
			}, params);
			
			if (localParams.$root == false) {
				return false;
			}
			
			/**
			 * We're retrieving information from the rel attribute of the
			 * parent LI.
			 *
			 * This is *always* encoded on the server, using rawurlencode,
			 * to prevent problems with single and double quotes, angled
			 * brackets, and so forth.
			 *
			 * The showTree method is the only point at which we want this
			 * string in it's unencoded form, because it makes it easier
			 * to process.
			 *
			 * showTree must NEVER write the unencoded string back to
			 * the DOM, or pass it outside of this method.
			 *
			 * Are we clear on that?
			 */
			
			// Shortcuts.
			var $li		= localParams.$root;
			var path	= decodeURIComponent(localParams.path);
			
			// Hold up, butt.
			$li.addClass('wait');

			// Load the bucket contents via AJAX.
			$.post(
				localOptions.ajaxScriptURL,
				{
					addon_id	: 'bucketlist',
					ajax		: 'y',
					dir			: path,
					request		: 'tree'
				},
				function(htmlFragment) {
					
					// Remove the initial "loading" message.
					if (initialLoad == true) {
						$('.initial-load', $this).fadeOut(function() {
							$(this).remove();
						});
						
						initialLoad = false;
					}
					
					// Remove the loading animation.
					$li.find('start').html('');
					$li.removeClass('wait').append(htmlFragment);
					
					// Add the click handlers to all branch links.
					$('li a', $li).bind('click', function(e) {
						treeClick($(e.target));
						return false;
					});
					
					if (isIE7()) {
						$li.find('ul:hidden').show();
						fix_ie7_matrices();
					} else {
						$li.find('ul:hidden').slideDown({duration : 500});
					}
					
					/**
					 * Remember to pass the UNESCAPED path out of the method.
					 */
					
					initializeUpload($li, localParams.path);
					
					// Execute the callback.
					localOptions.onBranchLoad({$root : $li, path : localParams.path});
					
					// Are we auto-displaying an initial file?
					if ($.isArray(initialFilePath)
						&& initialFilePath
						&& initialFileStep < initialFilePath.length) {
							
						pathToLoad = '';
						
						// Construct the complete path up to this point.
						for (var count = 0; count <= initialFileStep; count++) {
							pathToLoad += initialFilePath[count] + slash;
						}
						
						// If this is the final step, remove the forward slash.
						if (initialFileStep == initialFilePath.length - 1) {
							pathToLoad = pathToLoad.substring(0, pathToLoad.length - slash.length);
						}
						
						treeClick($li.parents('.eepro-co-uk').find('[rel="' + pathToLoad + '"]'));
						initialFileStep++;
					}
				}); /* $.get */
				
		}; /* showTree */
		
		// Starts the ball rolling.
		initializeTree();
		
	}); // this.each
}; // bucketlist


/**
 * Log a message to the JS console.
 *
 * @access	private
 * @param 	string		message		The text to log.
 */
function log(message) {
	if (window.console && window.console.log) {
		window.console.log(message);
	}
};


/**
 * Plugin defaults.
 */
$.fn.bucketlist.defaults = {
	ajaxScriptURL	: '',
	initialFile		: '',
	languageStrings	: {},
	onBranchLoad	: function(params) {},
	onFileClick		: function(params) {},
	onUploadFailure	: function(params) {},
	onUploadStart	: function(params) {},
	onUploadSuccess	: function(params) {},
	uploadFormAction : ''
};
	
})(jQuery);