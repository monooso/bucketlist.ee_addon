/**
 * BucketList JavaScript. Borrowed heavily from the jQuery File Tree plugin, by
 * Cory S.N. LaViska (http://abeautifulsite.net).
 *
 * @package		BucketList
 * @author 		Stephen Lewis (http://eepro.co.uk/)
 * @copyright 	Copyright (c) 2009, Stephen Lewis
 * @link 		http://eepro.co.uk/bucketlist/
 * @see 		http://abeautifulsite.net/
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
		
		// Flag to hide the initial loading message.
		var initialLoad = true;
		
		// Retrieve the initialFile, if it has been supplied.
		if (localOptions.initialFile) {
			var initialFilePath = localOptions.initialFile.split('/');
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
		};
		
		
		
		/**
		 * ----------------------------------------------------------
		 * UPLOADING FUNCTIONS
		 * ----------------------------------------------------------
		 */
		
		/**
		 * Initialises the 'upload' link in the specified branch. Wraps it
		 * in a div, and creates a new "file" input element.
		 *
		 * @access	private
		 * @param 	object		$root			A jQuery object containing the root of this branch.
		 * @param 	string 		bucketName		The name of the current bucket.
		 * @param	string		filePath		The path to the current folder.
		 * @return 	void
		 */
		function initializeUpload($root, bucketName, filePath) {
			
			var $uploadLink = $root.find('.upload a');
			
			if ($uploadLink.length == 0) {
				// Nothing more we can do here.
				return false;
			}

			// Create the element wrapper, and append the file element.
			$uploadLink.wrap('<div class="bucketload"></div>');
			
			// Create the file element.
			createFile($uploadLink.parent());
			
			// Create some hidden fields to hold the bucket and filePath info.
			$uploadLink.parent().append('<input type="hidden" name="bucket" value="' + bucketName + '">');
			$uploadLink.parent().append('<input type="hidden" name="path" value="' + filePath + '">');
			
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
			 * of dynamically generated iframe forms being re-submitted on page
			 * reload.
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
				$iframe.remove();
				return;
			}

			var status		= $iframe.contents().find('#status').text();
			var message		= $iframe.contents().find('#message').text();
			var uploadId	= $iframe.contents().find('#uploadId').text();
			var listItem	= $iframe.contents().find('#listItem').html();

			var params = {
				listItem	: listItem,
				message		: message,
				status		: status,
				uploadId	: uploadId
			}

			// Do we have the expected information?
			if (status == 'undefined' || message == 'undefined' || uploadId == 'undefined') {

				/**
				 * We assume the worse. A blank message is passed, so we don't
				 * have to hard-code the language string here.
				 */
				
				/**
				 * @todo Change this to use the language strings.
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
			}
			
			/**
			 * Add the new item to the target list.
			 */
			
			// Locate the correct bucket and folder.
			
			// Determine the point at which to insert the new item (alphabetically).
			
			// Insert the item, hidden and with a height of 0.
			
			// Fade in and slide down the item.

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

			var $file = $(e.target);
			var $parent = $file.parent('.bucketload');

			// Create the form.
			var $form = $('<form action="' + localOptions.uploadFormAction + '" enctype="multipart/form-data" method="post"></form>');

			// Create a unique ID for this upload.
			var uploadId = Math.round(Math.random() * new Date().getTime());
			
			// Retrieve the bucket and path.
			var bucketName 	= $parent.find('input[name="bucket"]').val();
			var filePath	= $parent.find('input[name="path"]').val();

			// Create the form fields.
			$form.append('<input type="hidden" name="bucket" value="' + bucketName + '">');
			$form.append('<input type="hidden" name="path" value="' + filePath + '">');
			$form.append('<input type="hidden" name="upload_id" value="' + uploadId + '">');

			/**
			 * Remove the file field listener. Incredibly important.
			 *
			 * jQuery doesn't remove the event handler automatically, and on document
			 * unload attempts to unbind an event on an object that no longer exists,
			 * in an iframe that no longer exists.
			 *
			 * IE then throws all lots of 'the bad man is fiddling with me' permission
			 * errors.
			 */

			$file.unbind('change');

			// Append the file field to the new form.
			$form.append($file);

			// Create and hide the iframe.
			var iframeId = 'bucketload-iframe-' + uploadId;

			var $iframe = $('<iframe id="' + iframeId + '" name="' + iframeId + '"></iframe>')
				.appendTo('body')
				.hide();

			// Wait a moment for the iframe to be added to the document.
			setTimeout(function() {
				// Populate the iframe.
				$iframe.contents().find('body').html($form);

				// Submit the form.
				$iframe.contents().find('form').submit();

				// Add a callback handler to the iframe.
				$iframe.bind('load', amazonResponse);

				// Create a new file field.
				createFile($parent);

				// Call the onStart handler.
				localOptions.onUploadStart({fileName : $file.val(), uploadId : uploadId});

			}, 1);

			return false;

		}; /* fileChange */
		
		
		/**
		 * Creates the input file element.
		 *
		 * @access	private
		 * @param 	object		$parent		A jQuery object.
		 * @return 	void
		 */
		function createFile($parent) {
			$('<input name="file" type="file">')
				.appendTo($parent)
				.bind('change', fileChange);
				
		}; /* createFile */
		
		
		
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
			// Bind the click event to all current and future bucketlist links.
			$('li a', $this).live('click', function(e) {
				handleTreeClick($(e.target));
				return false;
			});

			// Load the buckets.
			showTree($this, '');
			
		}; /* initializeTree */
		
		
		/**
		 * Loads a directory's sub-tree, or calls the callback handler when an
		 * item in the file tree is clicked.
		 *
		 * @access	private
		 * @param	jQuery object	$target		The click target.
		 */
		function handleTreeClick($target) {
			if ($target.parent().hasClass('directory')) {

				if ($target.parent().hasClass('collapsed')) {

					/**
					 * Expand the tree. Only one branch of the tree can be
					 * open at any one time.
					 */

					$target.parent().parent().find('ul').slideUp({duration : 500});
					$target.parent().parent().find('.directory').removeClass('expanded').addClass('collapsed');
					$target.parent().find('ul').remove();

					showTree($target.parent(), escape($target.attr('rel').match(/.*\//)));

					$target.parent().removeClass('collapsed').addClass('expanded');

				} else {
					// Collapse the tree.
					$target.parent().find('ul').slideUp({duration : 500});
					$target.parent().removeClass('expanded').addClass('collapsed');

				}

			} else {
				localOptions.onFileClick({$target : $target, fileName : $target.attr('rel')});
			}
			
		}; /* handleTreeClick */


		/**
		 * Expand the tree when a 'directory' element is clicked.
		 *
		 * @access	private
		 * @param	object		$li		A jQuery object containing the 'parent' list item.
		 * @param 	string		path	The 'file path' of the selected item.
		 */
		function showTree($li, path) {
			$li.addClass('wait');

			// Load the bucket contents via AJAX.
			$.get(
				localOptions.ajaxScriptURL,
				{dir: path},
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
					
					// If the path is empty, we're loading the root 'buckets'.
					if (path == '') {
						$li.find('ul:hidden').show();
					} else {
						$li.find('ul:hidden').slideDown({duration : 500});
						
						// Initialise the upload link for this branch.
						var bucketName 	= path.substring(0, path.indexOf('/'));
						var filePath	= path.substring(bucketName.length + 1);
						
						initializeUpload($li, bucketName, filePath);
						
						// Execute the callback.
						localOptions.onBranchLoad({$root : $li, filePath : path});
					}
					
					// Are we auto-displaying an initial file?
					if ($.isArray(initialFilePath)
						&& initialFilePath
						&& initialFileStep < initialFilePath.length) {
							
						pathToLoad = '';
						
						// Construct the complete path up to this point.
						for (var count = 0; count <= initialFileStep; count++) {
							pathToLoad += initialFilePath[count] + '/';
						}
						
						// If this is the final step, remove the forward slash.
						if (initialFileStep == initialFilePath.length - 1) {
							pathToLoad = pathToLoad.substring(0, pathToLoad.length - 1);
						}
						
						log(pathToLoad);
						log($li.parents('.eepro-co-uk').find('[rel="' + pathToLoad + '"]').length);
						
						handleTreeClick($li.parents('.eepro-co-uk').find('[rel="' + pathToLoad + '"]'));
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