// File: js/admin.js
jQuery(document).ready(function($) {
    $('#csv_file').on('change', function() {
        var fileName = $(this).val().split('\\').pop();
        $(this).next('.file-name').remove();
        $(this).after('<span class="file-name">' + fileName + '</span>');
    });

    $('#select-all').on('change', function() {
        $('input[name="confirm[]"]').prop('checked', $(this).prop('checked'));
    });

    $('form').on('submit', function(e) {
        var $confirmInputs = $(this).find('input[name="confirm[]"]');
        if ($confirmInputs.length === 0) {
            return;
        }
        if ($confirmInputs.filter(':checked').length === 0) {
            e.preventDefault();
            alert('Please select at least one change to apply.');
        }
    });
});