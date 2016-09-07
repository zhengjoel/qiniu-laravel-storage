<?php namespace zgldh\QiniuStorage;

use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\Adapter\Polyfill\NotSupportingVisibilityTrait;
use League\Flysystem\Adapter\Polyfill\StreamedReadingTrait;
use League\Flysystem\Config;
use Qiniu\Auth;
use Qiniu\Http\Error;
use Qiniu\Processing\Operation;
use Qiniu\Processing\PersistentFop;
use Qiniu\Storage\BucketManager;
use Qiniu\Storage\FormUploader;
use Qiniu\Storage\ResumeUploader;
use Qiniu\Storage\UploadManager;
use Qiniu\Config as QiniuConfig;

class QiniuUrl
{
    private $url = null;
    private $parameters = [];

    public function __construct($url)
    {
        $this->url = $url;
    }

    public function __toString()
    {
        $url = trim($this->getUrl(), '?&');

        $parameters = $this->getParameters();
        $parameterString = join('&', $parameters);

        if ($parameterString) {
            if (strrpos($url, '?') === false) {
                $url .= '?' . $parameterString;
            } else {
                $url .= '&' . $parameterString;
            }
        }
        if (is_string($url) === false) {
            return '';
        }
        return $url;
    }

    /**
     * @return null
     */
    public function getUrl()
    {
        return $this->url;
    }

    /**
     * @param null $url
     */
    public function setUrl($url)
    {
        $this->url = $url;
    }

    /**
     * @return null
     */
    public function getDownload()
    {
        return $this->getParameter('download');
    }

    /**
     * @param null $download
     */
    public function setDownload($download)
    {
        return $this->setParameter('download', urlencode($download));
    }

    /**
     * @return array
     */
    public function getParameter($name)
    {
        return $this->parameters[$name];
    }

    /**
     * @return array
     */
    public function getParameters()
    {
        return $this->parameters;
    }

    /**
     * @param array $parameters
     */
    public function setParameter($name, $value)
    {
        $this->parameters[$name] = $name . '/' . $value;
        return $this;
    }
}
