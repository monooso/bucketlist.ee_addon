/**
 * Loads files for the selected bucket over AJAX.
 *
 * @param		jQuery		$bucket_list		The target bucket list.
 */
function load_files($bucket_list) {
	
	// Initialise some variables.
	bucket_id 			= $bucket_list.val();
	$field_wrapper	= $bucket_list.parents('.sl-s3');
	$files_wrapper 	= $('.files', $field_wrapper);
	$files_list			= $('select', $files_wrapper);
	
	// Hide everything bar the 'loading' message.
	$files_wrapper.hide();
	$('.error, .info', $field_wrapper).hide();
	$('.loading', $field_wrapper).fadeIn();
	
	// Load the files list via AJAX.
	$.ajax({
		cache : false,
		data : {
			ajax_request : 'y',
			bucket_id : bucket_id
		},
		dataType : 'html',
		error : function(xhr, status, error) {
			// Hide the loading message, and display the error message.
			$('.loading', $field_wrapper).hide();
			$('.error', $field_wrapper).fadeIn();
		},
		success : function(html, status) {
			// Hide the loading message.
			$('.loading', $field_wrapper).hide();
			
			// If we have content, assume all is well. Otherwise display
			// an error message.
			if (html != '') {
				$files_list.html(html);
				$files_wrapper.fadeIn();
			} else {
				$('.error', $field_wrapper).fadeIn();
			}
		},
		type : 'GET',
		url : document.location.href
	});
}


/**
 * Handles the 'change' event on bucket lists.
 *
 * @param		Event object		e			The jQuery event object.
 */
function handle_bucket_change(e) {
	load_files($(e.target));
}


/**
 * Handles the 'click' event on 'remove' links.
 *
 * @param		Event object		e			The jQuery event object.
 */
function handle_remove_click(e) {
	$link = $(e.target);
	
	$link
		.parents('.saved-file')
		.fadeOut(function() {
			$(this)
				.parents('.sl-s3')
				.find('table')
				.fadeIn();
		})
}


/**
 * Attach event handlers.
 */
function attach_event_handlers() {
	$('select[id^=bucket-]').bind('change', handle_bucket_change);
	$('.remove').bind('click', handle_remove_click);
}


$(document).ready(function() {
	attach_event_handlers();				// Bind the event handlers.
})