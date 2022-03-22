<?php namespace zgldh\QiniuStorage;

use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\FilesystemException;
use League\Flysystem\InvalidVisibilityProvided;
use League\Flysystem\UnableToCheckExistence;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use Qiniu\Auth;
use Qiniu\Etag;
use Qiniu\Http\Error;
use Qiniu\Processing\Operation;
use Qiniu\Processing\PersistentFop;
use Qiniu\Storage\BucketManager;
use Qiniu\Storage\FormUploader;
use Qiniu\Storage\ResumeUploader;
use Qiniu\Storage\UploadManager;
use Qiniu\Config as QiniuConfig;

class QiniuAdapter implements FilesystemAdapter
{
    const ACCESS_PUBLIC = 'public';
    const ACCESS_PRIVATE = 'private';

    private $access_key = null;
    private $secret_key = null;
    private $bucket = null;
    private $domains = null;
    private $notify_url = null; //持久化处理后的回调地址
    private $access = null;

    private $auth = null;
    private $upload_manager = null;
    private $bucket_manager = null;
    private $operation = null;

    private $prefixedDomains = [];
    private $lastReturn = null;
    private $uploadToken = null;
    private $hotlinkPreventionKey = null;

    public function __construct(
        $access_key,
        $secret_key,
        $bucket,
        $domains,
        $notify_url = null,
        $access = self::ACCESS_PUBLIC,
        $hotlinkPreventionKey = null
    )
    {
        $this->access_key = $access_key;
        $this->secret_key = $secret_key;
        $this->bucket = $bucket;
        $this->domains = $domains;
        $this->setPathPrefix('http://' . $this->domains['default']);
        $this->setDomainPrefix('http://' . $this->domains['default'], 'default');
        $this->setDomainPrefix('https://' . $this->domains['https'], 'https');
        $this->setDomainPrefix('http://' . $this->domains['custom'], 'custom');
        $this->notify_url = $notify_url;
        $this->access = $access;
        $this->hotlinkPreventionKey = $hotlinkPreventionKey;
    }

    /**
     * Set the path prefix.
     *
     * @param string $prefix
     *
     * @return self
     */
    public function setDomainPrefix($prefix, $domainType)
    {
        $is_empty = empty($prefix);

        if (!$is_empty) {
            $prefix = rtrim($prefix, $this->pathSeparator) . $this->pathSeparator;
        }

        $prefixedDomain = $is_empty ? null : $prefix;
        $this->prefixedDomains[$domainType] = $prefixedDomain;
    }

    public function withUploadToken($token)
    {
        $this->uploadToken = $token;
    }

    private function getAuth()
    {
        if ($this->auth == null) {
            $this->auth = new Auth($this->access_key, $this->secret_key);
        }

        return $this->auth;
    }

    private function getUploadManager()
    {
        if ($this->upload_manager == null) {
            $this->upload_manager = new UploadManager();
        }

        return $this->upload_manager;
    }

    private function getBucketManager()
    {
        if ($this->bucket_manager == null) {
            $this->bucket_manager = new BucketManager($this->getAuth());
        }

        return $this->bucket_manager;
    }

    private function getOperation()
    {
        if ($this->operation == null) {
            $this->operation = new Operation(
                $this->domains['default'],
                $this->access === self::ACCESS_PUBLIC ? null : $this->getAuth()
            );
        }

        return $this->operation;
    }

    private function logQiniuError(Error $error, $extra = null)
    {
        \Log::error('Qiniu: ' . $error->code() . ' ' . $error->message() . '. ' . $extra);
    }

    /**
     * Update a file.
     *
     * @param string $path
     * @param string $contents
     * @param Config $config Config object
     *
     * @return array|false false on failure file meta data on success
     */
    public function update($path, $contents, Config $config)
    {
        return $this->write($path, $contents, $config);
    }

    /**
     * Rewrite Qiniu\Storage\UploadManager::putFile
     * @param $upToken
     * @param $key
     * @param $fileResource
     * @param null $params
     * @param string $mime
     * @param bool $checkCrc
     * @return mixed
     * @throws \Exception
     */
    private function qiniuPutFile(
        $upToken,
        $key,
        $fileResource,
        $params = null,
        $mime = 'application/octet-stream',
        $checkCrc = false
    )
    {
        if ($fileResource === false) {
            throw new \Exception("file can not open", 1);
        }
        $file = $fileResource;
        $params = UploadManager::trimParams($params);
        $stat = fstat($file);
        $size = $stat['size'];
        if ($size <= QiniuConfig::BLOCK_SIZE) {
            $data = fread($file, $size);
            fclose($file);
            if ($data === false) {
                throw new \Exception("file can not read", 1);
            }
            $result = FormUploader::put(
                $upToken,
                $key,
                $data,
                new QiniuConfig(),
                $params,
                $mime,
                basename($key)
            );
            return $result;
        }
        $up = new ResumeUploader(
            $upToken,
            $key,
            $file,
            $size,
            $params,
            $mime,
            new QiniuConfig()
        );
        $ret = $up->upload(basename($key));
        fclose($file);
        return $ret;
    }


    /**
     * Fetch a file.
     *
     * @DriverFunction
     * @param string $url
     * @param string $key
     *
     * @return bool|array
     */
    public function fetch($url, $key = null)
    {
        $bucketMgr = $this->getBucketManager();

        [$ret, $error] = $bucketMgr->fetch($url, $this->bucket, $key);
        if ($error !== null) {
            $this->logQiniuError($error, $this->bucket . '/' . $key);

            return false;
        } else {
            return $ret;
        }
    }

    /**
     * Create a directory.
     *
     * @param string $dirname directory name
     * @param Config $config
     *
     * @return array|false
     */
    public function createDir($dirname, Config $config)
    {
        return ['path' => $dirname];
    }


    /**
     * Get all the meta data of a file or directory.
     *
     * @param string $path
     *
     * @return array|false
     */
    private function getMetadata($path)
    {
        $bucketMgr = $this->getBucketManager();

        [$ret, $error] = $bucketMgr->stat($this->bucket, $path);
        if ($error !== null) {
            return false;
        } else {
            return $ret;
        }
    }

    /**
     * @DriverFunction
     * @param null $path
     * @param string $domainType
     * @return string|QiniuUrl
     */
    public function downloadUrl($path = null, $domainType = 'default')
    {
        if ($this->access == self::ACCESS_PRIVATE) {
            return $this->privateDownloadUrl($path, $domainType);
        }
        $this->pathPrefix = $this->prefixedDomains[$domainType];
        $location = $this->applyPathPrefix($path);
        $location = new QiniuUrl($location, $this->hotlinkPreventionKey);

        return $location;
    }

    /**
     * @DriverFunction
     * @param mixed $path
     * @return string
     */
    public function getUrl($path)
    {
        if (is_string($path)) {
            return $this->downloadUrl($path, 'default')->getUrl();
        }

        if (is_array($path)) {
            return $this->downloadUrl($path['path'], $path['domainType'])->getUrl();
        }

        return $this->downloadUrl('', 'default')->getUrl();

    }

    /**
     * @DriverFunction
     * @param $path
     * @param string|array $settings ['domain'=>'default', 'expires'=>3600]
     * @return string
     */
    public function privateDownloadUrl($path, $settings = 'default')
    {
        $expires = 3600;
        $domain = 'default';
        if (is_array($settings)) {
            $expires = isset($settings['expires']) ? $settings['expires'] : $expires;
            $domain = isset($settings['domain']) ? $settings['domain'] : $domain;
        } else {
            $domain = $settings;
        }
        $this->pathPrefix = $this->prefixedDomains[$domain];
        $auth = $this->getAuth();
        $location = $this->applyPathPrefix($path);
        $authUrl = $auth->privateDownloadUrl($location, $expires);
        $authUrl = new QiniuUrl($authUrl);

        return $authUrl;
    }

    /**
     * @DriverFunction
     * @param null $path
     * @param null $fops
     * @param null $pipline
     * @param bool $force
     * @return bool
     */
    public function persistentFop($path = null, $fops = null, $pipline = null, $force = false, $notifyUrl = null)
    {
        $auth = $this->getAuth();

        $pfop = new PersistentFop($auth);

        $notifyUrl = is_null($notifyUrl) ? $this->notify_url : $notifyUrl;
        [$id, $error] = $pfop->execute($this->bucket, $path, $fops, $pipline, $notifyUrl, $force);

        if ($error != null) {
            $this->logQiniuError($error);

            return false;
        } else {
            return $id;
        }
    }

    /**
     * @DriverFunction
     * @param $id
     * @return array
     */
    public function persistentStatus($id)
    {
        $auth = $this->getAuth();
        $pfop = new PersistentFop($auth);
        return $pfop->status($id);
    }

    /**
     * @DriverFunction
     * @param null $path
     * @return bool
     */
    public function avInfo($path = null)
    {
        $operation = $this->getOperation();

        [$ret, $error] = $operation->execute($path, 'avinfo');

        if ($error !== null) {
            $this->logQiniuError($error);

            return false;
        } else {
            return $ret;
        }
    }

    /**
     * @DriverFunction
     * @param null $path
     * @return bool
     */
    public function imageInfo($path = null)
    {
        $operation = $this->getOperation();

        [$ret, $error] = $operation->execute($path, 'imageInfo');

        if ($error !== null) {
            $this->logQiniuError($error);

            return false;
        } else {
            return $ret;
        }
    }

    /**
     * @DriverFunction
     * @param null $path
     * @return bool
     */
    public function imageExif($path = null)
    {
        $operation = $this->getOperation();

        [$ret, $error] = $operation->execute($path, 'exif');

        if ($error !== null) {
            $this->logQiniuError($error);

            return false;
        } else {
            return $ret;
        }
    }

    /**
     * @DriverFunction
     * @param null $path
     * @param null $ops
     * @return string|QiniuUrl
     */
    public function imagePreviewUrl($path = null, $ops = null)
    {
        if ($this->access == self::ACCESS_PRIVATE) {
            return $this->privateImagePreviewUrl($path, $ops);
        }
        $operation = $this->getOperation();
        $url = $operation->buildUrl($path, $ops);
        $url = new QiniuUrl($url, $this->hotlinkPreventionKey);

        return $url;
    }

    /**
     * @DriverFunction
     * @param null $path
     * @param null $ops
     * @return string|QiniuUrl
     */
    public function privateImagePreviewUrl($path = null, $ops = null)
    {
        $auth = $this->getAuth();
        $operation = $this->getOperation();
        $url = $operation->buildUrl($path, $ops);
        $authUrl = $auth->privateDownloadUrl($url);
        $authUrl = new QiniuUrl($authUrl);

        return $authUrl;
    }

    /**
     * @DriverFunction
     * @param null $path
     * @param int $expires
     * @param null $policy
     * @param bool $strictPolicy
     * @return string
     */
    public function uploadToken(
        $path = null,
        $expires = 3600,
        $policy = null,
        $strictPolicy = true
    )
    {
        $auth = $this->getAuth();

        $token = $auth->uploadToken(
            $this->bucket,
            $path,
            $expires,
            $policy,
            $strictPolicy
        );

        return $token;
    }

    /**
     * @DriverFunction
     * @param $contentType
     * @param $originAuthorization
     * @param $url
     * @param $body
     * @return bool
     */
    public function verifyCallback($contentType, $originAuthorization, $url, $body)
    {
        $auth = $this->getAuth();

        return $auth->verifyCallback($contentType, $originAuthorization, $url, $body);
    }

    /**
     * @DriverFunction
     * @param $localFilePath
     * @return array
     */
    public function calculateQetag($localFilePath)
    {
        return Etag::sum($localFilePath);
    }

    /**
     * @DriverFunction
     * @return null
     */
    public function getLastQetag()
    {
        if ($this->lastReturn && isset($this->lastReturn['hash'])) {
            return $this->lastReturn['hash'];
        }
        return null;
    }

    /**
     * @DriverFunction
     * @return null
     */
    public function getLastReturn()
    {
        return $this->lastReturn;
    }

    /**
     * Write a new file.
     *
     * @param string $path
     * @param string $contents
     * @param Config $config Config object
     *
     * @return array|false false on failure file meta data on success
     */
    public function write(string $path, string $contents, Config $config): void
    {
        $auth = $this->getAuth();

        $token = $this->uploadToken ?: $auth->uploadToken($this->bucket, $path);
        $this->withUploadToken(null);

        $params = $config->get('params', null);
        $mime = $config->get('mime', 'application/octet-stream');
        $checkCrc = $config->get('checkCrc', false);

        $upload_manager = $this->getUploadManager();
        [$ret, $error] = $upload_manager->put($token, $path, $contents, $params, $mime, $checkCrc);

        if ($error !== null) {
            $this->logQiniuError($error);
            throw UnableToWriteFile::atLocation($path, $error->message());
        } else {
            $this->lastReturn = $ret;
        }
    }

    /**
     * Write using a stream.
     *
     * @param string $path
     * @param $contents
     * @param Config $config
     *
     * @return mixed false or file metadata
     * @throws \Exception
     */
    public function writeStream(string $path, $contents, Config $config): void
    {
        $auth = $this->getAuth();

        $token = $this->uploadToken ?: $auth->uploadToken($this->bucket, $path);
        $this->withUploadToken(null);

        $params = $config->get('params', null);
        $mime = $config->get('mime', 'application/octet-stream');
        $checkCrc = $config->get('checkCrc', false);

        [$ret, $error] = $this->qiniuPutFile($token, $path, $contents, $params, $mime, $checkCrc);

        if ($error !== null) {
            $this->logQiniuError($error);
        } else {
            $this->lastReturn = $ret;
        }
    }

    /**
     * Read a file.
     *
     * @param string $path
     *
     * @return array|false
     */
    public function read(string $path): string
    {
        $location = $this->applyPathPrefix($path);
        $content = file_get_contents($location);
        if (false === $content) {
            throw UnableToReadFile::fromLocation($path);
        }
        return $content;
    }

    public function readStream(string $path)
    {
        if (ini_get('allow_url_fopen')) {
            if ($result = fopen($this->getUrl($path), 'r')) {
                return $result;
            }
        }

        throw UnableToReadFile::fromLocation($path);
    }


    /**
     * List contents of a directory.
     *
     * @param string $path
     * @param bool $deep
     * @return array
     */
    public function listContents(string $path, bool $deep): iterable
    {
        $bucketMgr = $this->getBucketManager();

        [$ret, $error] = $bucketMgr->listFiles($this->bucket, $path);
        $items = @$ret['items'];
        $marker = @$ret['marker'];
        $commonPrefixes = @$ret['commonPrefixes'];
        if ($error !== null) {
            $this->logQiniuError($error);

            return [];
        } else {
            $contents = [];
            foreach ($items as $item) {
                $normalized = [
                    'type'      => 'file',
                    'path'      => $item['key'],
                    'timestamp' => $item['putTime'],
                ];

                if ($normalized['type'] === 'file') {
                    $normalized['size'] = $item['fsize'];
                }

                array_push($contents, $normalized);
            }

            return $contents;
        }
    }

    /**
     * Copy a file.
     *
     * @param string $source
     * @param string $destination
     * @param Config $config
     * @return void
     */
    public function copy(string $source, string $destination, Config $config): void
    {
        $bucketMgr = $this->getBucketManager();

        [$ret, $error] = $bucketMgr->copy($this->bucket, $source, $this->bucket, $destination);
        if ($error !== null) {
            $this->logQiniuError($error);
            throw UnableToCopyFile::fromLocationTo($source, $destination);
        }
    }

    /**
     * Delete a file.
     *
     * @param string $path
     *
     * @return bool
     */
    public function delete(string $path): void
    {
        $bucketMgr = $this->getBucketManager();

        [$ret, $error] = $bucketMgr->delete($this->bucket, $path);
        if ($error !== null) {
            $this->logQiniuError($error, $this->bucket . '/' . $path);
            throw UnableToDeleteFile::atLocation($path);
        }
    }

    public function fileExists(string $path): bool
    {
        $meta = $this->getMetadata($path);
        if ($meta) {
            return true;
        }

        return false;
    }

    public function directoryExists(string $path): bool
    {
        return $this->fileExists($path);
    }

    public function deleteDirectory(string $path): void
    {
        $this->delete($path);
    }

    public function createDirectory(string $path, Config $config): void
    {
        // Do not need to create directory. Just use write() to save your content.
    }

    public function setVisibility(string $path, string $visibility): void
    {
        throw UnableToSetVisibility::atLocation($path);
    }

    public function visibility(string $path): FileAttributes
    {
        throw UnableToSetVisibility::atLocation($path);
    }

    public function mimeType(string $path): FileAttributes
    {
        $stat = $this->getMetadata($path);
        if ($stat) {
            return new FileAttributes($path, null, null, null, $stat['mimeType']);
        }
        throw UnableToRetrieveMetadata::mimeType($path);
    }

    public function lastModified(string $path): FileAttributes
    {
        $stat = $this->getMetadata($path);
        if ($stat) {
            return new FileAttributes($path, null, null, $stat['putTime']);
        }
        throw UnableToRetrieveMetadata::mimeType($path);
    }

    public function fileSize(string $path): FileAttributes
    {
        $stat = $this->getMetadata($path);
        if ($stat) {
            return new FileAttributes($path, $stat['fsize']);
        }
        throw UnableToRetrieveMetadata::mimeType($path);
    }

    public function move(string $source, string $destination, Config $config): void
    {
        $bucketMgr = $this->getBucketManager();
        [$ret, $error] = $bucketMgr->move($this->bucket, $source, $this->bucket, $destination);
        if ($error !== null) {
            $this->logQiniuError($error);
            throw UnableToMoveFile::fromLocationTo($source, $destination);
        }
    }

    /**
     * @var string|null path prefix
     */
    protected $pathPrefix;

    /**
     * @var string
     */
    protected $pathSeparator = '/';

    /**
     * Set the path prefix.
     *
     * @param string $prefix
     *
     * @return void
     */
    public function setPathPrefix($prefix)
    {
        $prefix = (string)$prefix;

        if ($prefix === '') {
            $this->pathPrefix = null;
            return;
        }

        $this->pathPrefix = rtrim($prefix, '\\/') . $this->pathSeparator;
    }

    /**
     * Get the path prefix.
     *
     * @return string|null path prefix or null if pathPrefix is empty
     */
    public function getPathPrefix()
    {
        return $this->pathPrefix;
    }

    /**
     * Prefix a path.
     *
     * @param string $path
     *
     * @return string prefixed path
     */
    public function applyPathPrefix($path)
    {
        return $this->getPathPrefix() . ltrim($path, '\\/');
    }

}
