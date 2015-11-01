<?php namespace Fuzz\S3;

/**
 * @file
 * S3 file conveyor.
 */

use Aws\S3\S3Client;
use Aws\S3\Enum\CannedAcl;
use Fuzz\S3\Exception\S3Exception;
use Aws\S3\Sync\UploadSyncBuilder;
use Aws\S3\Sync\DownloadSyncBuilder;
use Aws\S3\Exception\NoSuchKeyException;
use Aws\Common\Exception\TransferException;
use Aws\S3\Model\MultipartUpload\UploadBuilder;
use Aws\Common\Exception\MultipartUploadException;

class Conveyor
{

	const S3_SCHEME        = 'http';
	const S3_SCHEME_SECURE = 'https';
	const S3_BASE_URL      = '.s3.amazonaws.com';
	const S3_VISIBILITY    = CannedAcl::PUBLIC_READ;
	const S3_CACHE_LENGTH  = 31104000; // Time from "now" in seconds

	private $file_category = null;
	private $s3_client;
	private $bucket;

	/**
	 * Class constructor.
	 *
	 * @param string $key
	 * @param string $bucket
	 * @param string $file_category
	 * @param boolean $debug
	 *
	 * @return void
	 *
	 * @throws  S3Exception If bucket not found and debug is on
	 */
	public function __construct($key, $secret, $bucket, $file_category = null, $debug = false)
	{
		// Instantiate a new Amazon S3 Client
		$this->s3_client = S3Client::factory([
			'key'          => $key,
			'secret'       => $secret,
		]);

		// Set our bucket
		$this->setBucket($bucket);

		// Set our file category
		$this->setFileCategory($file_category);

		if ($debug) {
			// Make sure that our bucket exists/we have access to it
			$this->verifyAccess();
		}
	}

	/**
	 * Can we connect to S3? Do we have a real bucket?
	 *
	 * @return void
	 *
	 * @throws  S3Exception If bucket not found
	 */
	private function verifyAccess()
	{
		if (! $this->s3_client->doesBucketExist($this->bucket)) {
			throw new S3Exception(
				'S3 Bucket ' . $this->bucket . ' not found in list of buckets'
			);
		}
	}

	/**
	 * Set the file category.
	 *
	 * @param string $category
	 *     Category of the file on S3
	 *
	 * @return string
	 *     The new category we jsut set
	 */
	public function setFileCategory($category)
	{
		// Make sure we actually passed something. We'll make 'undefined' an illegal value
		// so JavaScript doesn't confound the category system
		if (! is_null($category) && $category !== 'undefined') {
			// Set our property
			$this->file_category = $category;
		}

		// Return for good measure
		return $this->file_category;
	}

	/**
	 * Set the bucket.
	 *
	 * @param string $bucket
	 *     Name of the bucket on S3
	 *
	 * @return string
	 *     Name of the bucket we just set
	 */
	public function setBucket($bucket)
	{
		// Make sure we actually passed something
		if (! is_null($bucket)) {
			// Set our property
			$this->bucket = $bucket;
		}

		// Return for good measure
		return $this->bucket;
	}

	/**
	 * Get the category path.
	 *
	 * @param string $category
	 *     Category of the file on S3
	 *
	 * @return string
	 *     The current category path with a trailing '/'
	 */
	public function getCategoryPath($category = null)
	{
		// If we passed a category, try to set it
		if (! is_null($category)) {
			$category = $this->setFileCategory($category);
		} else {
			// Get our category
			$category = $this->file_category;

			// If our category is still null, just return here
			if (is_null($category)) {
				return null;
			}
		}

		// If the category is an array
		if (is_array($category)) {
			// Filter out all null-like values
			$category = array_filter($category);

			// Stringify our category
			$category = implode('/', $category);
		}

		// Return the category name with a trailing slash
		return $category . '/';
	}

	/**
	 * Sync a local directory with S3.
	 *
	 * @param string $directory
	 * @param mixed $category
	 * @param boolean $download
	 * @return void
	 * @throws S3Exception
	 */
	public function syncLocalDirectory($directory, $category = null, $download = false)
	{
		if (! is_dir($directory)) {
			throw new S3Exception(sprintf('Directory %s does not exist', $directory));
		}

		if (! is_null($category)) {
			$this->setFileCategory($category);
		}

		if ($download) {
			$builder = DownloadSyncBuilder::getInstance()
				->setDirectory($directory)
				->setClient($this->s3_client)
				->setBucket($this->bucket)
				->setKeyPrefix($this->getCategoryPath())
				->setConcurrency(5);
		} else {
			$builder = UploadSyncBuilder::getInstance()
				->uploadFromDirectory($directory)
				->setClient($this->s3_client)
				->setBucket($this->bucket)
				->setKeyPrefix($this->getCategoryPath())
				->setConcurrency(5)
				->setBaseDir(realpath($directory))
				->setAcl(self::S3_VISIBILITY);
		}

		$builder->build()->transfer();
	}

	/**
	 * Get the S3 base URL.
	 *
	 * @param bool $secure
	 *     Flag to return the secure (i.e. https) or insecure (i.e. http) version of the base url
	 *
	 * @return string
	 *     The base url
	 */
	public function getBaseS3Url($secure = false)
	{
		// Return our concatenated constants
		return ($secure ? self::S3_SCHEME_SECURE : self::S3_SCHEME) . '://' . $this->bucket . self::S3_BASE_URL . '/';
	}

	/**
	 * Get the S3 full URL.
	 *
	 * @param string $file_name
	 *     Name of the file on S3
	 * @param string $category
	 *     Category of the file on S3
	 * @param bool $secure
	 *     Flag to return the secure (i.e. https) or insecure (i.e. http) url
	 *
	 * @return string
	 *      The full S3 url
	 */
	public function getFullS3Url($file_name, $category = null, $secure = false)
	{
		// If we passed a category, try to set it
		if (! is_null($category)) {
			$category = $this->setFileCategory($category);
		} else {
			// Get our category
			$category = $this->file_category;
		}

		// Get the URL parts
		$base_url = $this->getBaseS3Url($secure);
		$category_path = $this->getCategoryPath();

		// Return the full url from the parts
		return $base_url . $category_path . $file_name;
	}

	/**
	 * Get the raw data from a potential API upload
	 * (whether it's multi-part form data or a base64 encoded string)
	 *
	 * @param array|string $upload_param
	 *     File upload meta (array) or base64 encoded file data (string)
	 *
	 * @return string
	 *      The raw data for the file that is to be uploaded.
	 *      Null if no file or an unsupported format, false if base64_decode fails
	 */
	public function getApiUploadRaw($upload_param)
	{
		// Allow multiple forms/methods of file uploads
		// Uploaded file
		if (is_array($upload_param) && isset($upload_param['tmp_name'])) {
			// Get the binary data from a file
			$file_data = file_get_contents($upload_param['tmp_name']);
		} elseif (is_string($upload_param)) { // File as base64-encoded string
			// Get the binary data by base64 decoding the passed param
			$file_data = base64_decode($upload_param, true);

			// Make sure that we got valid data
			if ($file_data === false) {
				return false;
			}
		} else {
			// Either no file, or an unsupported format
			return null;
		}

		// Return our file data
		return $file_data;
	}

	/**
	 * Get an object's raw info from S3.
	 *
	 * @param string $filename
	 *     Name of the file on S3
	 * @param string $category
	 *     Name of the bucket on S3
	 *
	 * @return array
	 *     Data properties of the object stored on S3
	 */
	public function getObjectRawInfo($filename, $category = null)
	{
		// Get the full path first
		$filename = $this->getCategoryPath($category) . $filename;

		// Get the object with our S3 client,
		// and return the info
		try {
			return $this->s3_client->headObject([
				'Bucket' => $this->bucket,
				'Key' => $filename,
			])->toArray();
		} catch (NoSuchKeyException $e) {
			throw new S3Exception('No such file exists');
		}
	}

	/**
	 * Stash a file from S3 to a temporary location for manipulation.
	 *
	 * @param string $filename
	 *     Name of the file on s3
	 * @param string $category
	 *     Category of the file on s3
	 * @param string $tmp_prefix
	 *     Prefix for the new local temporary file
	 *
	 * @return string|false
	 *     Returns the absolute path to the local temporary file
	 */
	public function stashObject($filename, $category = null, $tmp_prefix = 's3-file-')
	{
		$filename = $this->getCategoryPath($category) . $filename;
		$tmp_file = tempnam('/tmp', $tmp_prefix);

		try {
			$this->s3_client->getObject([
				'Bucket' => $this->bucket,
				'Key' => $filename,
				'SaveAs' => $tmp_file,
			]);

			return $tmp_file;
		} catch (NoSuchKeyException $e) {
			// ...
		}

		return false;
	}

	/**
	 * Get an object's extended info.
	 *
	 * @param string $filename
	 *     Name of the file on S3
	 * @param string $category
	 *     Category of the file on S3
	 *
	 * @return array
	 *      Data array for the object
	 *      file_name -- name of the file on s3
	 *      time_created -- creation timestamp
	 *      file_hash -- contains checksum hashes for the file
	 *      mime_type -- the mime type of the file
	 *      size -- filesize in bytes, kilobytes, and megabytes
	 *      url -- full S3 url
	 */
	public function getObjectInfo($filename, $category = null)
	{
		// If we passed a category, try to set it
		if (! is_null($category)) {
			$category = $this->setFileCategory($category);
		}

		// Get the raw object info
		$raw_info = $this->getObjectRawInfo($filename, $category);

		// Let's cast and sexify the info
		return [
			'file_name'    => (string) $filename,
			'time_created' => (int) $raw_info['time'],
			'file_hash'    => [
				'md5' => (string) $raw_info['hash'],
			],
			'mime_type' => (string) $raw_info['type'],
			'size'      => [
				'bytes'     => (int) $raw_info['size'],
				'kilobytes' => round(((int) $raw_info['size'] / 1024), 1),
				'megabytes' => round(((int) $raw_info['size'] / 1024 / 1024), 1),
			],
			'url' => ($this->getFullS3Url($filename)),
		];
	}

	/**
	 * Get an object's raw binary data.
	 *
	 * @param string $filename
	 *     Name of the file on s3
	 * @param string $category
	 *     Name of the bucket on s3
	 *
	 * @return string
	 *      Raw filedata as stored on s3
	 */
	public function getObjectRaw($filename, $category = null)
	{
		// Get the full path first
		$filename = $this->getCategoryPath($category) . $filename;

		try {
			// Get the object with our S3 client
			$object = $this->s3_client->getObject([
				'Bucket' => $this->bucket,
				'Key'    => $filename,
			]);

			// Return the raw data from S3
			return isset($object['Body']) ? (string) $object['Body'] : false;
		} catch (S3Exception $e) {
			return false;
		}
	}

	/**
	 * Upload a resource to S3.
	 *
	 * @param resource $object_resource
	 *        A handle on the raw data to upload
	 * @param string $name
	 *        The name of the file to upload
	 * @param string $mime_type
	 *        The value for the Content-Type header (i.e. 'image/jpeg')
	 * @param $bucket
	 *        The bucket to upload to.
	 * @param $visibility
	 *        The ACL setting for the object
	 * @param $cache_length
	 *        The cache length for the object
	 *
	 * @return bool
	 *      True on successful upload, false on failure
	 */
	private function putObject($s3_resource, $name, $mime_type = null, $bucket = null, $visibility = self::S3_VISIBILITY, $cache_length = self::S3_CACHE_LENGTH)
	{
		// Make SURE that we were passed a name
		if (! isset($name) || is_null($name)) {
			// Throw an exception
			throw new S3Exception('No name provided');
		}

		// Do we have a mime_type set?
		if (is_null($mime_type) !== true) {
			// Set it in our request headers
			$request_headers = [
				'ContentType' => $mime_type
			];
		}
		else {
			$request_headers = [];
		}

		// Let's add our cache/expire headers
		$request_headers['CacheControl'] = 'max-age=' . (string) $cache_length . ', public';
		$request_headers['Expires'] = gmdate('D, d M Y H:i:s T', (time() + $cache_length));

		// Get the full path first
		$destination_name = $this->getCategoryPath() . $name;

		// Upload the object with our S3 client,
		// and return the results
		$uploader = UploadBuilder::newInstance()
			->setClient($this->s3_client)
			->setSource($s3_resource)
			->setBucket($this->bucket)
			->setKey($destination_name)
			->setMinPartSize(25 * 1024 * 1024)
			->setOption('ACL', $visibility);

		foreach ($request_headers as $option_name => $value) {
			$uploader->setOption($option_name, $value);
		}

		try {
			$transfer = $uploader->setConcurrency(5)->build();
			$transfer->upload();
			return true;
		} catch (\Exception $e) {
			if (isset($transfer)) {
				$transfer->abort();
			}

			return $this->putObject($s3_resource, $name, $mime_type, $bucket, $visibility, $cache_length);
		}
	}

	/**
	 * Delete an object.
	 * Not advisable.
	 *
	 * @param string $filename
	 *     Name of the file to be deleted.  Must be in the current category
	 *
	 * @return bool
	 *     True if delete succeeded, false otherwise
	 */
	public function deleteObject($filename)
	{
		// Get the full path first
		$filename = $this->getCategoryPath() . $filename;

		// Delete the object and return the success boolean
		$this->s3_client->deleteObject([
			'Bucket' => $this->bucket,
			'Key'    => $filename,
		]);

		return true;
	}

	/**
	 * Upload an object from raw data.
	 *
	 * @param resource|string $file_data
	 *     The base64-encoded (string) or FileHandle contents (resource)
	 * @param string $name
	 *     Name of the new file on s3
	 * @param string $mime_type
	 *     Encoding for the file data (i.e image/jpeg)
	 *
	 * @return bool
	 *      True if successful upload, false otherwise
	 */
	public function uploadRawObject($file_data, $name, $mime_type = null)
	{
		// Make sure we were passed a name
		if (! isset($name) || is_null($name)) {
			// Throw an exception
			throw new S3Exception('No name provided');
		}

		// Create a temporary file to hold our data
		$object_resource = tmpfile();

		// Write our raw data to the file
		fwrite($object_resource, $file_data);

		// Rewind the file pointer
		fseek($object_resource, 0);

		// Upload the object with our S3 client,
		// and return the results
		$success = $this->putObject($object_resource, $name, $mime_type);

		// If our resource wasn't closed
		if (is_resource($object_resource)) {
			// Remove our temporary file resource
			fclose($object_resource);
		}

		// Return our success or failure
		return $success;
	}
}
