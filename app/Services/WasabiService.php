<?php
namespace App\Services;

use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;
use Google\Cloud\Storage\StorageClient;
use Illuminate\Support\Facades\DB;
use App\Services\CryptService;

class WasabiService
{
    protected $client;
    protected $bucket;
    protected $storageType;
    protected $subadminId;

    public function __construct($subadminId = null)
    {
        $this->subadminId = $subadminId;

        // Fetch settings
        $settings = DB::table('setting')
            ->where('subadmin_id', $subadminId)
            ->first();

        if (!$settings) {
            throw new \Exception("No storage settings found for subadmin_id {$subadminId}");
        }

        // Determine storage type (wasabi, aws, google)
        $this->storageType = strtolower($settings->storage_type ?? 'wasabi');

        switch ($this->storageType) {
            case '2':
                $this->initAWS($settings);
                break;

            case '3':
                $this->initGoogleCloud($settings);
                break;

            case '1':
            default:
                $this->initWasabi($settings);
                break;
        }
    }

    /**
     * Initialize Wasabi
     */
    protected function initWasabi($settings)
    {
        $key      = CryptService::decryptData($settings->wasabi_api_key);
        $secret   = CryptService::decryptData($settings->wasabi_secret_key);
        $region   = CryptService::decryptData($settings->wasabi_region);
        $endpoint = CryptService::decryptData($settings->wasabi_endpoint);
        $bucket   = CryptService::decryptData($settings->wasabi_bucket);

        $this->client = new S3Client([
            'credentials' => [
                'key'    => $key,
                'secret' => $secret,
            ],
            'region'  => $region,
            'version' => 'latest',
            'endpoint' => $endpoint,
            'use_path_style_endpoint' => true,
        ]);

        $this->bucket = $bucket;
    }

    /**
     * Initialize AWS S3
     */
    protected function initAWS($settings)
    {
        $key    = CryptService::decryptData($settings->aws_api_key);
        $secret = CryptService::decryptData($settings->aws_secret_key);
        $region = CryptService::decryptData($settings->aws_region);
        $bucket = CryptService::decryptData($settings->aws_bucket);

        $this->client = new S3Client([
            'credentials' => [
                'key'    => $key,
                'secret' => $secret,
            ],
            'region'  => $region,
            'version' => 'latest',
        ]);

        $this->bucket = $bucket;
    }

    /**
     * Initialize Google Cloud Storage
     */
    protected function initGoogleCloud($settings)
{
    $projectId     = CryptService::decryptData($settings->google_project_id);
    $bucket        = CryptService::decryptData($settings->google_bucket);
    $clientEmail   = CryptService::decryptData($settings->google_client_email);
    $privateKey    = CryptService::decryptData($settings->google_private_key);
    $privateKeyId  = CryptService::decryptData($settings->google_private_key_id);
    $clientId      = CryptService::decryptData($settings->google_client_id);
    $tokenUri      = 'https://oauth2.googleapis.com/token';

    // Build credentials array dynamically
    $credentials = [
        'type'                        => 'service_account',
        'project_id'                  => $projectId,
        'private_key_id'              => $privateKeyId,
        'private_key'                 => $privateKey,
        'client_email'                => $clientEmail,
        'client_id'                   => $clientId,
        'auth_uri'                    => 'https://accounts.google.com/o/oauth2/auth',
        'token_uri'                   => $tokenUri,
        'auth_provider_x509_cert_url' => 'https://www.googleapis.com/oauth2/v1/certs',
        'client_x509_cert_url'        => "https://www.googleapis.com/robot/v1/metadata/x509/" . urlencode($clientEmail),
    ];

    // Create Google Cloud client directly from array (no file)
    $this->client = new \Google\Cloud\Storage\StorageClient([
        'projectId' => $projectId,
        'keyFile'   => $credentials, // ✅ keyFile instead of keyFilePath
    ]);

    $this->bucket = $bucket;
}


    /**
     * Upload file for all clouds
     */
    public function uploadFile($folder, $file)
    {
        $filename = time() . '_' . rand(100000, 999999) . '_' . $file->getClientOriginalName();
        $key = $folder . '/' . $filename;

        switch ($this->storageType) {
            case '2':
            case '1':
                return $this->uploadToS3($key, $file);

            case '3':
                return $this->uploadToGoogle($key, $file);
        }

        throw new \Exception("Unsupported storage type: {$this->storageType}");
    }

    /**
     * Upload to S3/Wasabi
     */
    protected function uploadToS3($key, $file)
    {
        try {
            $params = [
                'Bucket' => $this->bucket,
                'Key'    => $key,
                'SourceFile' => $file->getPathname(),
                'ContentDisposition' => 'attachment; filename="' . $file->getClientOriginalName() . '"',
            ];

            if ($this->storageType == 1) {
                $params['ACL'] = 'public-read';
            }

            $result = $this->client->putObject($params);
            return $result['ObjectURL'];
        } catch (S3Exception $e) {
            throw new \Exception(ucfirst($this->storageType) . " upload failed: " . $e->getMessage());
        }
    }

    /**
     * Upload to Google Cloud
     */
    protected function uploadToGoogle($key, $file)
    {
        try {
            $bucket = $this->client->bucket($this->bucket);
            $bucket->upload(fopen($file->getPathname(), 'r'), [
                'name' => $key,
            ]);
            return "https://storage.googleapis.com/{$this->bucket}/{$key}";
        } catch (\Exception $e) {
            throw new \Exception("Google Cloud upload failed: " . $e->getMessage());
        }
    }

    /**
     * Delete file for all providers
     */
    public function deleteFile($url)
    {
        switch ($this->storageType) {
            case 'aws':
            case 'wasabi':
                return $this->deleteFromS3($url);

            case 'google':
                return $this->deleteFromGoogle($url);
        }

        throw new \Exception("Unsupported storage type: {$this->storageType}");
    }

    protected function deleteFromS3($url)
    {
        if ($this->storageType === 'wasabi') {
            $pattern = "https://s3.wasabisys.com/{$this->bucket}/";
        } else {
            $region = $this->client->getRegion();
            $pattern = "https://{$this->bucket}.s3.{$region}.amazonaws.com/";
        }

        $key = urldecode(str_replace($pattern, '', $url));

        try {
            $this->client->deleteObject([
                'Bucket' => $this->bucket,
                'Key'    => $key,
            ]);
            return true;
        } catch (S3Exception $e) {
            throw new \Exception(ucfirst($this->storageType) . " delete failed: " . $e->getMessage());
        }
    }

    protected function deleteFromGoogle($url)
    {
        try {
            $key = urldecode(str_replace("https://storage.googleapis.com/{$this->bucket}/", '', $url));
            $bucket = $this->client->bucket($this->bucket);
            $object = $bucket->object($key);
            $object->delete();
            return true;
        } catch (\Exception $e) {
            throw new \Exception("Google Cloud delete failed: " . $e->getMessage());
        }
    }
}
