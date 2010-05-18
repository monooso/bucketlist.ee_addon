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
	
	// FF Matrix 1.x
	if (typeof Matrix == 'undefined' && typeof $.fn.ffMatrix != 'undefined') {
		
		/**
		 * FF Matrix's onDisplayCell method is a bit flakey, and has a habit
		 * of running twice. Setting a class 'flag' on the td is the best
		 * solution I've found.
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
	
	// Matrix 2.x
	if (typeof Matrix != 'undefined') {
		
		/**
		 * The display event doesn't fire when the page is loaded, so we
		 * need to manually initialise any pre-loaded BucketList fieldtypes.
		 */
		
		$('td.matrix > .bl-wrapper').each(function() {
			$(this).bucketlist({initialFile : $(this).find(':hidden').val()});
		});
		
		// Now bind the event for all cells created on-the-fly.
		Matrix.bind('bucketlist', 'display', function(cell) {
			$(cell.dom.$td).find('.bl-wrapper').each(function() {
				$(this).bucketlist({initialFile : $(this).find(':hidden').val()});
			});
		});
	}
	
	
	
	/**
	 * ------------------------------------------------------------------
	 * Settings.
	 * ------------------------------------------------------------------
	 */
	
	$('.bl-instructions div a').live('click', function(e) {
		
		// IE7 is so useless it can't even handle the slideToggle.
		if (document.all && navigator.appVersion.indexOf('MSIE 7.') != -1) {
			$(this)
				.toggleClass('bl-open')
				.closest('div')
				.find('ul')
				.toggle();
		} else {
			$(this)
				.toggleClass('bl-open')
				.closest('div')
				.find('ul')
				.slideToggle('slow');
		}
		
		return false;
	});
	
	$('.bl-settings a[class*=bl-toggle]').live('click', function(e) {
		
		$this = $(this);
		toggleParent = false;

		if ($this.hasClass('bl-toggle-show')) {
			targetId = '[show]';
			toggleParent = true;
		} else if ($this.hasClass('bl-toggle-upload')) {
			targetId = '[allow_upload]';
		} else if ($this.hasClass('bl-toggle-all-files')) {
			targetId = '[all_files]';
		} else {
			targetId = '';
		}

		if (targetId) {
			$target = $this.parent('li').find('input[name$=' +targetId +']');
			$target.val($target.val() == 'y' ? 'n' : 'y');

			$target.val() == 'y'
				? $this.removeClass('bl-disabled')
				: $this.addClass('bl-disabled');
		}

		if (toggleParent) {
			$target.val() == 'y'
				? $this.parent('li').removeClass('bl-disabled')
				: $this.parent('li').addClass('bl-disabled');
		}

		return false;
	});
	
});