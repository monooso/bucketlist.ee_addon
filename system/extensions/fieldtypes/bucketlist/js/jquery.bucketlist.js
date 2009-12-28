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
		
		log('Initial File: ' + localOptions.initialFile);
		
		// Retrieve the initialFile, if it has been supplied.
		if (localOptions.initialFile) {
			var initialFilePath = localOptions.initialFile.split('/');
			var initialFileStep = 0;
		}
		
		
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

				} // if (collapsed)

			} else {
				localOptions.callbackHandler($target.attr('rel'), $target);
			} // if (directory)
		};


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

					path == '' ? $li.find('ul:hidden').show() : $li.find('ul:hidden').slideDown({duration : 500});
					
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
				}); // $.get
		}; // showTree

		// Bind the click event to all current and future bucketlist links.
		$('li a', $this).live('click', function(e) {
			handleTreeClick($(e.target));
			return false;
		});

		// Load the buckets.
		showTree($this, '');
		
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
	ajaxScriptURL			: '',
	callbackHandler	: null,
	initialFile		: ''
};
	
})(jQuery);