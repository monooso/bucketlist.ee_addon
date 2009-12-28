/**
 * JavaScript for the BucketList FieldFrame field type.
 *
 * @package		BucketList
 * @author 		Stephen Lewis (http://eepro.co.uk/)
 * @copyright 	Copyright (c) 2009, Stephen Lewis
 * @license 	Commercial license, URL here.
 * @link 		http://eepro.co.uk/bucketlist/
 */

/**
 * Called when a file is selected in the file browser.
 *
 * @access	public
 * @param 	string		filename	The filename (including full path).
 * @param	object 		$target		A jQuery object containing the target anchor element.
 */
function handleFileClick(filename, $target) {
	// Make a note of the selected filename.
	$target.parents('.eepro-co-uk').find(':hidden').val(filename);
	$target.parents('.eepro-co-uk').find('.selected').removeClass('selected');
	$target.parent().addClass('selected');
}


/**
 * Gets the ball rolling.
 */
$(document).ready(function() {
	// Set the bucketlist defaults.
	$.fn.bucketlist.defaults.ajaxScriptURL 		= ajaxScriptURL;
	$.fn.bucketlist.defaults.callbackHandler	= handleFileClick;
	
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