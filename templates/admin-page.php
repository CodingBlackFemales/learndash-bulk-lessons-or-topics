<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}
?>
<div class="wrap">
	<?php
	global $extended_learndash_bulk_create;
	if ( isset( $extended_learndash_bulk_create ) && $extended_learndash_bulk_create instanceof Extended_LearnDash_Bulk_Create ) {
		$extended_learndash_bulk_create->render_bulk_admin_notice();
	}
	?>
	<h1><?php esc_html_e( 'LearnDash bulk import', 'extended-learndash-bulk-create' ); ?></h1>
	<p class="description">
		<?php esc_html_e( 'Import CSV rows into the selected content type. New titles create posts; existing titles are skipped unless you enable overwrite. If more than one post shares a title, that row will error until duplicates are resolved.', 'extended-learndash-bulk-create' ); ?>
	</p>
	<form method="post" enctype="multipart/form-data">
		<?php wp_nonce_field( 'extended_learndash_bulk_create', 'extended_learndash_bulk_create_nonce' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="content_type"><?php esc_html_e( 'Content type', 'extended-learndash-bulk-create' ); ?></label></th>
				<td>
					<select name="content_type" id="content_type">
						<option value="sfwd-courses"><?php esc_html_e( 'Courses', 'extended-learndash-bulk-create' ); ?></option>
						<option value="sfwd-lessons"><?php esc_html_e( 'Lessons', 'extended-learndash-bulk-create' ); ?></option>
						<option value="sfwd-topic"><?php esc_html_e( 'Topics', 'extended-learndash-bulk-create' ); ?></option>
						<option value="sfwd-quiz"><?php esc_html_e( 'Quizzes', 'extended-learndash-bulk-create' ); ?></option>
						<option value="sfwd-question"><?php esc_html_e( 'Questions', 'extended-learndash-bulk-create' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="csv_file"><?php esc_html_e( 'CSV file', 'extended-learndash-bulk-create' ); ?></label></th>
				<td>
					<input type="file" name="csv_file" id="csv_file" accept=".csv,text/csv" required>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Overwrite', 'extended-learndash-bulk-create' ); ?></th>
				<td>
					<label for="eldbc_overwrite">
						<input type="checkbox" name="eldbc_overwrite" id="eldbc_overwrite" value="1">
						<?php esc_html_e( 'Overwrite existing posts matched by optional ID or unique title', 'extended-learndash-bulk-create' ); ?>
					</label>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="eldbc_media_files"><?php esc_html_e( 'Media folder (optional)', 'extended-learndash-bulk-create' ); ?></label></th>
				<td>
					<input type="file" name="media_files[]" id="eldbc_media_files" multiple webkitdirectory>
					<p class="description">
						<?php esc_html_e( 'Select the exported folder that contains image paths referenced in post_content (e.g. media/slide_01.png). Chrome/Edge: choose the folder; paths in HTML should match.', 'extended-learndash-bulk-create' ); ?>
					</p>
				</td>
			</tr>
		</table>
		<p class="submit">
			<input type="submit" name="submit" id="submit" class="button button-primary" value="<?php esc_attr_e( 'Import CSV', 'extended-learndash-bulk-create' ); ?>">
		</p>
	</form>
	<p>
		<a href="<?php echo esc_url( plugin_dir_url( dirname( __FILE__ ) ) . 'templates/bulk_template.csv' ); ?>" download><?php esc_html_e( 'Download CSV template', 'extended-learndash-bulk-create' ); ?></a>
	</p>
</div>
