/**
 * JavaScript for the BucketList FieldFrame field type.
 *
 * @package		BucketList
 * @author 		Stephen Lewis (http://eepro.co.uk/)
 * @copyright 	Copyright (c) 2009, Stephen Lewis
 * @link 		http://eepro.co.uk/bucketlist/
 */

/**
 * ------------------------------------------------------------------
 * BucketLoad JavaScript (the uploading stuff).
 * ------------------------------------------------------------------
 */

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
	$statusBar = $('#bucketload-status');
	
	// Where are we heading to?
	targetBottom = (showStatus === false) ? -$statusBar.outerHeight(true) * 1.5 : 0;
	
	// Animate!
	$statusBar.stop(true, false).animate({bottom : targetBottom}, 500, function() {
		statusActive = (showStatus !== false);
	});
}


/**
 * Removes an upload item from the list.
 *
 * @access	private
 * @param 	string		uploadId		The upload ID of the item to remove.
 * @return 	void.
 */
function removeUpload(uploadId) {
	
	// Shorthand.
	$item = $('#bucketload-status-' + uploadId);
	
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


/**
 * Notifies the user of a successful upload.
 *
 * @access	private
 * @param 	string 		status		The status (always 'success').
 * @param	string 		message		The success message to display.
 * @param 	string 		uploadId	The ID of the uploaded item.
 */
function uploadSuccess(status, message, uploadId) {
	$('li#bucketload-status-' + uploadId)
		.removeClass('active')
		.addClass('complete')
		.html(message);
		
	setTimeout('removeUpload("' + uploadId + '")', 1500);
}


/**
 * Notifies the user of a failed upload.
 *
 * @access	private
 * @param	string		status		The status (always 'failure').
 * @param 	string		message 	The failure message to diplay.
 * @param 	string 		uploadId	The ID of the failed item.
 */
function uploadFailure(status, message, uploadId) {
	
	if (message == '') {
		message = languageStrings['uploadFailureGeneric'];
	}
	
	$('#bucketload-status li#bucketload-status-' + uploadId)
		.removeClass('active')
		.addClass('error')
		.html(message);
}


/**
 * Notifies the user that the upload has started, by adding an item
 * to the upload list.
 *
 * @access	private
 * @param 	string 		fileName	The name of the file being uploaded.
 * @param 	string 		uploadId	The upload ID of the item.
 */
function uploadStart(fileName, uploadId) {
	
	// Stupid IE has to be different, and include some nonsense path with the file name.
	fileName = fileName.replace(/.*(\/|\\)/, '');
	
	// Create the new item, append it to the list, and fade it in.
	$li = $('<li class="active" id="bucketload-status-' + uploadId + '">' + fileName + '</li>');
	$li.appendTo('#bucketload-status ul').hide().fadeIn('fast');
	
	// If the status bar is currently hidden, show it.
	if (statusActive == false) {
		displayStatusBar(true);
	}
}


/**
 * Called when a tree branch is loaded. Initialises the file upload for the
 * specified branch.
 *
 * @access	public
 * @param 	object 		$target		A jQuery object containing the branch parent.
 * @param 	string		filePath	The filePath for this branch (bucket/folder/subFolder/).
 */
function handleBranchLoad($target, filePath) {
	// Extract the bucket and path.
	bucketName 	= filePath.substring(0, filePath.indexOf('/'));
	filePath	= filePath.substring(bucketName.length + 1);
	
	$target.find('.upload a').bucketload({
		bucket		: bucketName,
		filePath	: filePath
	});
}



/**
 * ------------------------------------------------------------------
 * BucketList JavaScript (the file tree stuff).
 * ------------------------------------------------------------------
 */

/**
 * Called when a file is selected in the file browser.
 *
 * @access	public
 * @param	object 		$target		A jQuery object containing the target anchor element.
 * @param 	string		filename	The filename (including full path).
 */
function handleFileClick($target, filename) {
	// Make a note of the selected filename.
	$target.parents('.eepro-co-uk').find(':hidden').val(filename);
	$target.parents('.eepro-co-uk').find('.selected').removeClass('selected');
	$target.parent().addClass('selected');
}



/**
 * ------------------------------------------------------------------
 * Initialisation.
 * ------------------------------------------------------------------
 */

$(document).ready(function() {
	
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
	
	baseAjaxURL	= document.location.href;
	baseAjaxURL	+= (baseAjaxURL.indexOf('?') === false) ? '?' : '&';
	baseAjaxURL	+= 'ajax=y&addon_id=bucketlist&request=';
	
	
	/**
	 * ------------------------------------------------------------------
	 * BucketLoad.
	 * ------------------------------------------------------------------
	 */
	
	// Create the upload status bar.
	$('body').append('<div id="bucketload-status"><ul></ul></div>');
	
	// Hide the status bar, in case it isn't already.
	displayStatusBar(false);

	// Set the defaults, so we don't have to do it for each instance.
	$.fn.bucketload.defaults.formAction	= baseAjaxURL + 'upload';
	$.fn.bucketload.defaults.onFailure 	= uploadFailure;
	$.fn.bucketload.defaults.onStart	= uploadStart;
	$.fn.bucketload.defaults.onSuccess	= uploadSuccess;
	
	
	/**
	 * ------------------------------------------------------------------
	 * BucketList.
	 * ------------------------------------------------------------------
	 */
	
	$.fn.bucketlist.defaults.ajaxScriptURL 	= baseAjaxURL + 'tree';
	$.fn.bucketlist.defaults.onFileClick	= handleFileClick;
	$.fn.bucketlist.defaults.onBranchLoad	= handleBranchLoad;
	
	// Initialise non-matrix file trees.
	$('.ff-ft > .eepro-co-uk > .bucketlist-ui').each(function() {
		$(this).bucketlist({initialFile : $(this).parents('.eepro-co-uk').find('input:hidden').val()});
	});
	
	if (typeof $.fn.ffMatrix != 'undefined') {
		// Initialise matrix file trees. Also handles new table cells as they are created.
		$.fn.ffMatrix.onDisplayCell.bucketlist = function($td, $ffm) {
			$('.bucketlist-ui', $td).each(function() {
				$(this).bucketlist({initialFile : $(this).parents('.eepro-co-uk').find('input:hidden').val()});
			});
		}
	}
});