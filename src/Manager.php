<?php namespace Fuzz\S3;

use Symfony\Component\HttpFoundation\Request;
use Fuzz\S3\Conveyor;
use Fuzz\S3\Image\SmartUploader;
use Fuzz\File\File;
use Fuzz\File\Resizer;

/**
 * @file
 * S3 file manager.
 */
class Manager
{
	/**
	 * A file conveyor instance.
	 *
	 * @var Fuzz\S3\Conveyor
	 */
	public $conveyor;

	/**
	 * A Symfony framework request.
	 *
	 * @var Symfony\Component\HttpFoundation\Request
	 */
	public $request;

	/**
	 * Class constructor.
	 *
	 * @param string $key
	 * @param string $bucket
	 * @param string $file_category
	 *
	 * @return void
	 */
	public function __construct($key, $secret, $bucket)
	{
		// Make a conveyor
		$this->conveyor = new Conveyor($key, $secret, $bucket);

		// Make a Symfony Request object
		$this->request = Request::createFromGlobals();
	}

	/**
	 * Method accessor for pre-fabricated Conveyor object.
	 *
	 * @return Fuzz\S3\Conveyor
	 */
	public function getConveyor()
	{
		return $this->conveyor;
	}

	/**
	 * Syncs a local directory with S3.
	 *
	 * @param string  $directory
	 * @param mixed   $category
	 * @param boolean $download
	 * @return void
	 */
	public function sync($directory, $category = null, $download = false)
	{
		$this->conveyor->syncLocalDirectory($directory, $category, $download);
	}

	/**
	 * Retrieves the full S3 URL for a specified file
	 *
	 * @param  string $file_name
	 *         Name of the file on S3
	 * @param  string $category
	 *         Category of the file on S3
	 * @param  bool   $secure
	 *         Boolean flag to use the secure (i.e. https) or insecure (i.e. http) url
	 *
	 * @return string
	 *         Full S3 URL
	 */
	public function getUrl($file_name, $category = null, $secure = false)
	{
		return $this->conveyor->getFullS3Url($file_name, $category, $secure);
	}

	/**
	 * Retrieves the base URL for our S3 files
	 *
	 * @param  bool $secure
	 *         Boolean flag to use the secure (i.e. https) or insecure (i.e. http) url
	 *
	 * @return string
	 *         S3 Base URL
	 */
	public function getBaseUrl($secure = false)
	{
		return $this->conveyor->getBaseS3Url($secure);
	}

	/**
	 * Retrieve the file info for a specified file
	 *
	 * @param  string $filename
	 *         Name of the file on S3
	 * @param  string $category
	 *         Category of the file on S3
	 *
	 * @return array
	 *         The object's info
	 */
	public function getInfo($filename, $category = null)
	{
		return $this->conveyor->getObjectInfo($filename, $category);
	}

	/**
	 * Store a file to disk as a tmp file
	 *
	 * @param  string $filename
	 *         Name of the file on S3
	 * @param  string $category
	 *         Category of the file on S3
	 * @param  string $tmp_prefix
	 *         Prefix for the temporary file
	 *
	 * @return string
	 *         Full path to the tmp file
	 */
	public function stash($filename, $category = null, $tmp_prefix = 's3-file-')
	{
		return $this->conveyor->stashObject($filename, $category, $tmp_prefix);
	}

	/**
	 * Upload a file from Input to a location.
	 *
	 * @param string $file_var
	 *        The name of the file variable in $_FILES
	 * @param string $directory
	 *        Category for the file on s3
	 *
	 * @return Fuzz\File\File
	 *         File instance created from Input on success, or false on failure
	 */
	public function upload($file_var = 'upload', $directory = null)
	{
		// If the file was passed
		if ($this->request->files->has($file_var)) {
			$file = new File($this->request->files->get($file_var));

			// Return the success of the conveyance
			if ($this->convey($file, $directory)) {
				return $file;
			}
		}

		return false;
	}

	/**
	 * Upload a local file to a location.
	 *
	 * @param string $filename
	 *        Full path to the local file to be uploaded
	 * @param string $directory
	 *        Category for the file on s3
	 * @param string $mime_type
	 *        An optional user-specified MIME type for the upload
	 *
	 * @return Fuzz\File\File
	 *         Instance of the file we created on success, false on failure
	 */
	public function uploadFile($filename, $directory = null, $mime_type = null)
	{
		if ($file = File::createFromFile($filename, $mime_type)) {
			if ($this->convey($file, $directory)) {
				return $file;
			}
		}

		return false;
	}

	/**
	 * Upload a file object to a location.
	 *
	 * @param Fuzz\File\File $file
	 * @param string         $directory
	 *
	 * @return Fuzz\File\File
	 */
	public function uploadFileObject(File $file, $directory = null)
	{
		if ($this->convey($file, $directory)) {
			return $file;
		}

		return false;
	}

	/**
	 * Upload a raw blob to a location.
	 *
	 * @param string $blob
	 *        Raw binary data representing the file to upload
	 * @param        $directory
	 *        Category for the file on S3
	 *
	 * @return Fuzz\File\File
	 *         Instance of the file created from the binary data on success, false on failure
	 */
	public function uploadBlob($blob, $directory = null)
	{
		$file = File::createFromBlob($blob);

		if ($this->convey($file, $directory)) {
			return $file;
		}

		return false;
	}

	/**
	 * Upload an image to a location.
	 *
	 * @param string  $file_var
	 *        The name of the file variable in $_FILES
	 * @param string  $directory
	 *        Category for the file on S3
	 * @param array   $sizes
	 *        A Fuzz\File\Resizer-compatible array of sizes
	 * @param boolean $crop
	 *        Whether we should crop
	 *
	 * @return Fuzz\File\File
	 *         Instance of the file created from the Input data on success, false on failure
	 */
	public function uploadImage($file_var = 'upload', $directory = null, $sizes = null, $crop = false)
	{
		// First upload the original image
		if ($file = $this->upload($file_var, $directory)) {
			if ($this->conveyImages($file, $sizes, $crop)) {
				return $file;
			}
		}

		return false;
	}

	/**
	 * Upload an image file to a location.
	 *
	 * @param string  $file_var
	 *        The name of the file variable in $_FILES
	 * @param string  $directory
	 *        Category for the file on S3
	 * @param Array   $sizes
	 *        A Fuzz\File\Resizer-compatible array of sizes
	 * @param boolean $crop
	 *        Whether we should crop
	 *
	 * @return Fuzz\File\File
	 *         Instance of the file created from the Input data on success, false on failure
	 */
	public function uploadImageFile($file_var = 'upload', $directory = null, $sizes = null, $crop = false)
	{
		if ($file = $this->uploadFile($file_var, $directory)) {
			if ($this->conveyImages($file, $sizes, $crop)) {
				return $file;
			}
		}

		return false;
	}

	/**
	 * Upload an image file object to a location.
	 *
	 * @param Fuzz\File|File $file
	 *        A file object
	 * @param                $directory
	 *        Category for the file on S3
	 * @param Array          $sizes
	 *        A Fuzz\File\Resizer-compatible array of sizes
	 * @param boolean        $crop
	 *        Whether we should crop
	 *
	 * @return Fuzz\File\File
	 *         Instance of the file created from the binary data on success, false on failure
	 */
	public function uploadImageFileObject(File $file, $directory = null, $sizes = null, $crop = false)
	{
		if ($file = $this->uploadFileObject($file, $directory)) {
			if ($this->conveyImages($file, $sizes, $crop)) {
				return $file;
			}
		}

		return false;
	}

	/**
	 * Upload a raw image blob to a location.
	 *
	 * @param string  $blob
	 *        Raw binary data representing the image to upload
	 * @param         $directory
	 *        Category for the file on S3
	 * @param Array   $sizes
	 *        A Fuzz\File\Resizer-compatible array of sizes
	 * @param boolean $crop
	 *        Whether we should crop
	 *
	 * @return Fuzz\File\File
	 *         Instance of the file created from the binary data on success, false on failure
	 */
	public function uploadImageBlob($blob, $directory = null, $sizes = null, $crop = false)
	{
		if ($file = $this->uploadBlob($blob, $directory)) {
			if ($this->conveyImages($file, $sizes, $crop)) {
				return $file;
			}
		}

		return false;
	}

	/**
	 * Set the bucket to a different value.
	 *
	 * @param string $bucket
	 * @return static
	 */
	public function setBucket($bucket)
	{
		$this->conveyor->setBucket($bucket);

		return $this;
	}

	/**
	 * Convey files to S3.
	 *
	 * @param Fuzz\File\File $file
	 *        The file to convey.
	 * @param                directory
	 *        Category for the file on S3
	 *
	 * @return bool
	 *         True on successful raw upload, false otherwise
	 */
	private function convey(File $file, $directory = null)
	{
		$this->conveyor->setFileCategory($directory);

		return $this->conveyor->uploadRawObject($file->getRaw(), $file->getFullFilename(), $file->getMimeType());
	}

	/**
	 * Convey images to S3.  Same as conveying files except using the ImageUploader with resizing
	 *
	 * @param Fuzz\File\File $file
	 *        The file to Upload.
	 * @param Array          $sizes
	 *        An optional resize configuration
	 * @param boolean        $crop
	 *        Whether to crop thumbnails
	 *
	 * @return bool
	 *         True on successful resize and upload, false otherwise
	 */
	private function conveyImages(File $file, $sizes = null, $crop = false)
	{
		// Maybe we don't want to resize these images....
		if (! empty($sizes)) {
			$uploader = new SmartUploader(
				$this->conveyor, new Resizer($file, $sizes, $crop)
			);

			return $uploader->resizeAndUpload();
		}

		return true;
	}

	/**
	 * Checks if a file is already on S3
	 * Wrapper around Fuzz\S3\Conveyor::getObjectRawInfo().
	 *
	 * @param  string $filename
	 *         File to check if exists
	 * @param  string $category
	 *         Bucket on amazon s3
	 * @return bool
	 *         True if the file exists, false otherwise
	 */
	public function fileExists($filename, $category = null)
	{
		return (bool) $this->conveyor->getObjectRawInfo($filename, $category);
	}

	/**
	 * Delete a file.
	 *
	 * @param string $filename
	 * @return boolean
	 */
	public function deleteFile($filename)
	{
		return $this->conveyor->deleteObject($filename);
	}
}
