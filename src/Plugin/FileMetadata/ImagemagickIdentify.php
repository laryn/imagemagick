<?php

namespace Drupal\imagemagick\Plugin\FileMetadata;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\ImageToolkit\ImageToolkitManager;
use Drupal\file_mdm\FileMetadataException;
use Drupal\file_mdm\Plugin\FileMetadata\FileMetadataPluginBase;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * FileMetadata plugin for ImageMagick's identify results.
 *
 * @FileMetadata(
 *   id = "imagemagick_identify",
 *   title = @Translation("ImageMagick identify"),
 *   help = @Translation("File metadata plugin for ImageMagick identify results."),
 * )
 */
class ImagemagickIdentify extends FileMetadataPluginBase {

  /**
   * The image toolkit plugin manager.
   *
   * @var \Drupal\Core\ImageToolkit\ImageToolkitManager
   */
  protected $imageToolkitManager;

  /**
   * Constructs an ImagemagickIdentify plugin.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param array $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache_service
   *   The cache service.
   * @param \Drupal\Core\ImageToolkit\ImageToolkitManager $image_toolkit_manager
   *   The image toolkit plugin manager.
   */
  public function __construct(array $configuration, $plugin_id, array $plugin_definition, CacheBackendInterface $cache_service, ImageToolkitManager $image_toolkit_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $cache_service);
    $this->imageToolkitManager = $image_toolkit_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('cache.file_mdm'),
      $container->get('image.toolkit.manager')
    );
  }

  /**
   * {@inheritdoc}
   *
   * Supported keys are:
   *   'format' - ImageMagick's image format identifier.
   *   'width' - Image width.
   *   'height' - Image height.
   *   'exif_orientation' - Image EXIF orientation (only supported formats).
   *   'source_local_path' - The local file path from where the file was
   *     parsed.
   *   'frames_count' - Number of frames in the image.
   */
  public function getSupportedKeys($options = NULL) {
    return ['format', 'width', 'height', 'exif_orientation', 'source_local_path', 'frames_count'];
  }

  /**
   * {@inheritdoc}
   */
  protected function doGetMetadataFromFile() {
    $toolkit = $this->imageToolkitManager->createInstance('imagemagick');
    $toolkit->setSource($this->getUri());
    return $toolkit->identify();
  }

  /**
   * Validates a file metadata key.
   *
   * @return bool
   *   TRUE if the key is valid.
   *
   * @throws \Drupal\file_mdm\FileMetadataException
   *   In case the key is invalid.
   */
  protected function validateKey($key, $method) {
    if (!is_string($key)) {
      throw new FileMetadataException("Invalid metadata key specified", $this->getPluginId(), $method);
    }
    if (!in_array($key, $this->getSupportedKeys(), TRUE)) {
      throw new FileMetadataException("Invalid metadata key '{$key}' specified", $this->getPluginId(), $method);
    }
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  protected function doGetMetadata($key = NULL) {
    if ($key === NULL) {
      return $this->metadata;
    }
    else {
      $this->validateKey($key, __FUNCTION__);
      switch ($key) {
        case 'source_local_path':
          return isset($this->metadata['source_local_path']) ? $this->metadata['source_local_path'] : NULL;

        case 'frames_count':
          return isset($this->metadata['frames']) ? count($this->metadata['frames']) : 0;

        default:
          return isset($this->metadata['frames'][0][$key]) ? $this->metadata['frames'][0][$key] : NULL;

      }
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function doSetMetadata($key, $value) {
    $this->validateKey($key, __FUNCTION__);
    switch ($key) {
      case 'source_local_path':
        $this->metadata['source_local_path'] = $value;
        return TRUE;

      case 'frames_count':
        return FALSE;

      default:
        $this->metadata['frames'][0][$key] = $value;
        return TRUE;

    }
  }

  /**
   * {@inheritdoc}
   */
  protected function doRemoveMetadata($key) {
    $this->validateKey($key, __FUNCTION__);
    switch ($key) {
      case 'source_local_path':
        if (isset($this->metadata['source_local_path'])) {
          unset($this->metadata['source_local_path']);
          return TRUE;
        }
        return FALSE;

      default:
        return FALSE;

    }
  }

}
