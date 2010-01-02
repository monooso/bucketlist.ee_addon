/**
 * BucketList Upload JavaScript.
 *
 * @package		BucketList
 * @author 		Stephen Lewis (http://eepro.co.uk/)
 * @copyright 	Copyright (c) 2009, Stephen Lewis
 * @link 		http://eepro.co.uk/bucketlist/
 */

(function($) {

$.fn.bucketload = function(options) {
	
	// Build main options.
	var globalOptions = $.extend({}, $.fn.bucketload.defaults, options);
	
	// Iterate through each matching element.
	return this.each(function() {
		
		// Convenience variable.
		$this = $(this);
		
		// Element-specific options.
		var localOptions = $.meta ? $.extend({}, globalOptions, $this.data()) : globalOptions;
		
		
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
			
			// Do we have the expected information?
			if (status == 'undefined' || message == 'undefined' || uploadId == 'undefined') {
				
				/**
				 * We assume the worse. A blank message is passed, so we don't
				 * have to hard-code the language string here.
				 */
				
				localOptions.onFailure('failure', '', uploadId);
				
			} else {
				// Summon the handlers.
				(status == 'success')
					? localOptions.onSuccess(status, message, uploadId)
					: localOptions.onFailure(status, message, uploadId);
			}
			
			/**
			 * Originally we were setting this to "javascript: '<html></html>';"
			 * but that crashes Safari when the Web Inspector is open. Seriously.
			 */
			
			e.target.src = "javascript: false;";
		};
		
		
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
			var $form = $('<form action="' + localOptions.formAction + '" enctype="multipart/form-data" method="post"></form>');
			
			// Create a unique ID for this upload.
			var uploadId = Math.round(Math.random() * new Date().getTime());
			
			// Create the form fields.
			$form.append('<input type="hidden" name="bucket" value="' + localOptions.bucket + '">');
			$form.append('<input type="hidden" name="path" value="' + localOptions.filePath + '">');
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
				localOptions.onStart($file.val(), uploadId);
				
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
		};
		
		
		/**
		 * Wraps the target element in a div, and creates a new "file" input
		 * element.
		 *
		 * @access	private
		 * @return 	void
		 */
		function initialize() {
			
			// Create the element wrapper, and append the file element.
			$this.wrap('<div class="bucketload"></div>');
			
			// Create the file element.
			createFile($this.parent());
			
			// If this is an anchor (which it should be), disable the default click event.
			if ($this[0].nodeName.toLowerCase() == 'a') {
				$this.bind('click', function(e) {
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
			
			$this.parent().bind('mousemove', function(e) {
				
				var $file	= $(this).find('input[type="file"]');
				
				var offset	= $(this).offset();
				var fileX	= e.pageX - offset.left - ($file.width() - 30);
				var fileY	= e.pageY - offset.top - ($file.height() / 2);
				
				$file.css('left', fileX);
				$file.css('top', fileY);
				
			});
				
		}; /* initialize */
		
		// Starts the ball rolling.
		initialize();
		
	}); /* this.each */
	
}; /* $.fn.bucketload */


// Defaults.
$.fn.bucketload.defaults = {
	bucket		: '',
	filePath	: '',
	onFailure	: function(status, message, uploadId) {},
	formAction 	: '',
	onStart		: function(fileName, uploadId) {},
	onSuccess	: function(status, message, uploadId) {}
};
	
})(jQuery);