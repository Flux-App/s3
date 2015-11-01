<?php namespace Fuzz\S3\Image;

/**
 * @file
 * S3 image conveyor.
 */

use Fuzz\S3\Conveyor;
use Fuzz\File\Resizer;

class SmartUploader extends Conveyor
{

	private $conveyor;
	private $resizer;
	private $image_meta;

	/**
	 * @param \Fuzz\S3\Conveyor $conveyor
	 * @param \Fuzz\File\Resizer $resizer
	 *
	 * @return void
	 */
	public function __construct(Conveyor $conveyor, Resizer $resizer = null)
	{
		// Set our conveyor
		$this->conveyor = $conveyor;

		// Set our resizer
		$this->resizer = $resizer;

		$this->initializeImageData();
	}

	/**
	 * Set up our image metadata.
	 *
	 * @return void
	 */
	private function initializeImageData()
	{
		// Set our image meta
		$this->image_meta = array(
			'obfuscated_name' => $this->resizer->file->getFilename(),
			'mime_type'       => $this->resizer->file->getMimeType(),
			'file_ext'        => '.' . $this->resizer->file->getExtension(),
		);
	}

	/**
	 * Upload raw image data to s3
	 *
	 * @param  string $image_data
	 *         Base64 encoded image binary
	 * @param  string $name
	 *         Name for the new upload
	 * @param  string $mime_type
	 *         Mime type of binary data.  I.e. image/jpeg
	 *
	 * @return bool
	 *         True if image successfully uploaded to S3, false otherwise
	 *
	 * @throws  S3Exception If name not provided
	 */
	public function upload($image_data = null, $name = null, $mime_type = null)
	{
		// If no image data was passed
		if (is_null($image_data)) {
			// Get our raw image data from our file object
			$image_data = $this->resizer->file->raw;
		}
		// If no name was passed
		if (is_null($name)) {
			// Get our name by obfuscating our file object's name
			// Append our file extension to our file's name
			$name = $this->image_meta['obfuscated_name'] . $this->image_meta['file_ext'];
		}
		// If no mime type was passed
		if (is_null($mime_type)) {
			// Get our mime type from our image meta
			$mime_type = $this->image_meta['mime_type'];
		}

		// Upload our file to S3
		return $this->conveyor->uploadRawObject(
			$image_data,
			$name,
			$mime_type
		);
	}

	/**
	 * Resize and upload the image.
	 *
	 * @return bool
	 *         True on success, false otherwise
	 */
	public function resizeAndUpload()
	{
		// Get our original "sizes" array from our iterator
		$sizes_array = $this->resizer->sizes;

		// Setup our success before the loop
		$success = true; // For keeping track of MULTIPLE successes

		// Loop over the Resizer using the Iterator interface
		foreach ($this->resizer as $image_size_name => $resized_image) {
			// Get our MIME type from our sizes array
			$mime_type = isset( $sizes_array[$image_size_name]['mime_type'] )
				? $sizes_array[$image_size_name]['mime_type']
				: $this->image_meta['mime_type'];

			// Get our file extension from our sizes array (or default to our original image's)
			$file_extension = isset( $sizes_array[$image_size_name]['format'] )
				? '.' . $sizes_array[$image_size_name]['format'] // Prepend our "format" with a dot
				: $this->image_meta['file_ext'];

			// Put together our image name
			$image_name = $this->image_meta['obfuscated_name'] . '_' . $image_size_name . $file_extension;

			// Upload our file to S3
			$success = $success && $this->upload(
				$resized_image,
				$image_name,
				$mime_type
			);
		}

		// Return our success
		return $success;
	}
}
