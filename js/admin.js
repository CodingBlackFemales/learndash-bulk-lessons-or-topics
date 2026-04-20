// LearnDash bulk import — file name hint for CSV picker.
jQuery(document).ready(function ($) {
	$('#csv_file').on('change', function () {
		var fileName = $(this).val().split('\\').pop().split('/').pop();
		$(this).next('.file-name').remove();
		$(this).after('<span class="file-name">' + fileName + '</span>');
	});
});
