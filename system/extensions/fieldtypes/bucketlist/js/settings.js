/**
 * JavaScript for the BucketList FieldFrame field type.
 *
 * @package		BucketList
 * @author 		Stephen Lewis <addons@experienceinternet.co.uk>
 * @copyright 	Copyright (c) 2009-2010, Stephen Lewis
 * @link 		http://experienceinternet.co.uk/bucketlist/
 */

jQuery(document).ready(function($) {
	
	$('.bl-wrapper a[class*=bl-toggle]').live('click', function(e) {
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