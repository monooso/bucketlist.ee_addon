/**
 * JavaScript for the BucketList FieldFrame field type.
 *
 * @package		BucketList
 * @author 		Stephen Lewis <addons@experienceinternet.co.uk>
 * @copyright 	Copyright (c) 2009-2010, Stephen Lewis
 * @link 		http://experienceinternet.co.uk/bucketlist/
 */

jQuery(document).ready(function($) {
	
	// Is the downloads status bar visible?
	statusActive = false;

	/**
	 * Controls the display of the status bar.
	 *
	 * @access	private
	 * @param 	bool	showStatus		Show or hide the status bar (default true).
	 * @return 	void
	 */
	function displayStatusBar(showStatus) {

		// Enough with the typing.
		$statusBar = $('#bl-status');

		// Where are we heading to?
		targetBottom = (showStatus === false) ? -$statusBar.outerHeight(true) * 1.5 : 0;

		// Animate!
		$statusBar.stop(true, false).animate({bottom : targetBottom}, 500, function() {
			statusActive = (showStatus !== false);
		});
	}


	/**
	 * Notifies the user of a successful upload.
	 *
	 * @access	private
	 * @param 	object		params		listItem, message, status, uploadId.
	 */
	function uploadSuccess(params) {
		$('li#bl-status-' + params['uploadId'])
			.removeClass('bl-active')
			.addClass('bl-complete')
			.html(params.message);

		setTimeout(removeUpload, 1500);
		
		// Removes the uploaded item from the status bar.
		function removeUpload() {
			$item = $('#bl-status-' + params['uploadId']);

			// Hide the item.
			$item.animate({width : 'hide', opacity : 0}, 500, function() {
				// If this is the last item, hide the bar.
				if ($(this).siblings().length == 0) {
					displayStatusBar(false);
				}

				// Remove this item.
				$(this).remove();
			});
		}
	}


	/**
	 * Notifies the user of a failed upload.
	 *
	 * @access	private
	 * @param	object		params		listItem, message, status, uploadId.
	 */
	function uploadFailure(params) {
		if (params.message == '' || params.message == 'undefined') {
			params.message = languageStrings.uploadFailureGeneric;
		}

		$('#bl-status li#bl-status-' + params.uploadId)
			.removeClass('bl-active')
			.addClass('bl-error')
			.html(params.message);
	}


	/**
	 * Notifies the user that the upload has started, by adding an item
	 * to the upload list.
	 *
	 * @access	private
	 * @param	object		params		fileName, uploadId.
	 */
	function uploadStart(params) {
		// Stupid IE has to be different, and include some nonsense path with the file name.
		fileName = params.fileName.replace(/.*(\/|\\)/, '');

		// Create the new item, append it to the list, and fade it in.
		$li = $('<li class="bl-active" id="bl-status-' + params.uploadId + '">' + fileName + '</li>');
		$li.appendTo('#bl-status ul').hide().fadeIn('fast');

		// If the status bar is currently hidden, show it.
		if (statusActive == false) {
			displayStatusBar(true);
		}
	}


	/**
	 * Alerts the user to in-progress uploads when he attempts to save, preview, or quick save
	 * the page. If there are no in-progress uploads, we let him proceed without question.
	 *
	 * @access	public
	 * @param 	object		e		jQuery event object.
	 */
	function handleSubmit(e) {
		pendingUploads = $('#bl-status li').length;

		if (typeof(pendingUploads) == 'number' && pendingUploads > 0) {
			return confirm(languageStrings.confirmExit);
		}
	}


	/**
	 * Alerts the user to in-progress uploads when he attempts to navigate away from
	 * the page, or close the window. If there are no in-progress uploads, we let him
	 * proceed without question.
	 *
	 * @access	public
	 * @param 	object		e		Native browser event object (event not bound using jQuery)
	 */
	function handleNavigate(e) {
		pendingUploads = $('#bl-status li').length;

		if (typeof(pendingUploads) == 'number' && pendingUploads > 0) {
			return languageStrings.confirmExit;
		}
	}
	
	
	
	/**
	 * ------------------------------------------------------------------
	 * Initialisation.
	 * ------------------------------------------------------------------
	 */
	
	selector = 'input[type="submit"][name="preview"], input[type="submit"][name="save"], input[type="submit"][name="submit"]';
	
	$(selector).bind('click', handleSubmit);
	window.onbeforeunload = handleNavigate;		// Do it the old-fashioned way. The jQuery event seems rather flakey.
	
	/**
	 * We post any AJAX requests back to the current URL, to be handled by
	 * the sessions_start hook.
	 * 
	 * The advantages of doing it this way are two-fold:
	 * 1. We know for certain that the FieldFrame extension will be instantiated
	 * for this page, so all the FF-dependent gumpf in the BucketList fieldtype
	 * will work as expected.
	 *
	 * 2. Everything will work in the CP, and in a SAEF. Spiffing.
	 */
	
	// Create the upload status bar.
	$('body').append('<div id="bl-status"><ul></ul></div>');
	
	// Hide the status bar, in case it isn't already.
	displayStatusBar(false);
	
	targetURL = document.location.href;
	
	$.fn.bucketlist.defaults.ajaxScriptURL 		= targetURL;
	$.fn.bucketlist.defaults.uploadFormAction	= targetURL;
	$.fn.bucketlist.defaults.onUploadFailure 	= uploadFailure;
	$.fn.bucketlist.defaults.onUploadStart		= uploadStart;
	$.fn.bucketlist.defaults.onUploadSuccess	= uploadSuccess;
	
	// Initialise non-matrix file trees.
	$('.ff-ft > .bl-wrapper').each(function() {
		$(this).bucketlist({initialFile : $(this).find(':hidden').val()});
	});
	
	// Initialise matrix file trees.
	if (typeof $.fn.ffMatrix != 'undefined') {
		
		/**
		 * Initialise matrix file trees. Also handles new table cells as
		 * they are created.
		 *
		 * This is the only way of doing this at present, but by Brandon's
		 * own admission it's a trifle flakey, and has a habit of running
		 * twice.
		 *
		 * I tried checking for the presence of the BucketList file tree,
		 * but that wasn't working. Setting a class 'flag' on the td
		 * works fine though.
		 *
		 * Of course, we're screwed if FF Matrix ever fails to trigger
		 * this method.
		 */
		
		$.fn.ffMatrix.onDisplayCell.bucketlist = function(td, matrix) {
			$td = $(td);
			
			if ($td.hasClass('.bl-ready') == false) {
				$td.addClass('bl-ready').find('.bl-wrapper').each(function() {
					$(this).bucketlist({initialFile : $(this).find(':hidden').val()});
				});
			}
		}
	}
});