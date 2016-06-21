<?php

namespace Drupal\imagemagick\Tests;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Image\ImageInterface;
use Drupal\file_mdm\FileMetadataInterface;
use Drupal\simpletest\WebTestBase;

/**
 * Tests that Imagemagick integrates properly with File Metadata Manager.
 *
 * @group Imagemagick
 */
class ToolkitImagemagickFileMetadataTest extends WebTestBase {

  /**
   * The image factory service.
   *
   * @var \Drupal\Core\Image\ImageFactory
   */
  protected $imageFactory;

  /**
   * A directory for image test file results.
   *
   * @var string
   */
  protected $testDirectory;

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = ['system', 'simpletest', 'file_test', 'imagemagick', 'file_mdm'];

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();

    // Create an admin user.
    $admin_user = $this->drupalCreateUser(array(
      'administer site configuration',
    ));
    $this->drupalLogin($admin_user);

    // Set the image factory.
    $this->imageFactory = $this->container->get('image.factory');

    // Prepare a directory for test file results.
    $this->testDirectory = 'public://imagetest';
  }

  /**
   * Test image toolkit integration with file metadata manager.
   */
  public function testFileMetadata() {
    $config = \Drupal::configFactory()->getEditable('imagemagick.settings');

    // The test can only be executed if the ImageMagick 'convert' is
    // available on the shell path.
    $status = \Drupal::service('image.toolkit.manager')->createInstance('imagemagick')->checkPath('');
    if (!empty($status['errors'])) {
      // Bots running automated test on d.o. do not have ImageMagick
      // installed, so there's no purpose to try and run this test there;
      // it can be run locally where ImageMagick is installed.
      debug('Tests for the Imagemagick toolkit cannot run because the \'convert\' executable is not available on the shell path.');
      return;
    }

    // Change the toolkit.
    \Drupal::configFactory()->getEditable('system.image')
      ->set('toolkit', 'imagemagick')
      ->save();
    // Set the toolkit on the image factory.
    $this->imageFactory->setToolkitId('imagemagick');
    $config->set('debug', TRUE)->save();


    // A list of files that will be tested.
    $files = array(
      'public://image-test.png' => array(
        'width' => 40,
        'height' => 20,
        'frames' => 1,
        'mimetype' => 'image/png',
      ),
      'public://image-test.gif' => array(
        'width' => 40,
        'height' => 20,
        'frames' => 1,
        'mimetype' => 'image/gif',
      ),
      'dummy-remote://image-test.jpg' => array(
        'width' => 40,
        'height' => 20,
        'frames' => 1,
        'mimetype' => 'image/jpeg',
      ),
      'public://test-multi-frame.gif' => array(
        'skip_dimensions_check' => TRUE,
        'frames' => 13,
        'mimetype' => 'image/gif',
      ),
    );

    // Setup a list of tests to perform on each type.
    $operations = array(
      'resize' => array(
        'function' => 'resize',
        'arguments' => array('width' => 20, 'height' => 10),
        'width' => 20,
        'height' => 10,
      ),
      'scale_x' => array(
        'function' => 'scale',
        'arguments' => array('width' => 20),
        'width' => 20,
        'height' => 10,
      ),
      'rotate_5' => array(
        'function' => 'rotate',
        'arguments' => array('degrees' => 5, 'background' => '#FF00FF'), // Fuchsia background.
        'width' => 41,
        'height' => 23,
      ),
      'convert_jpg' => array(
        'function' => 'convert',
        'width' => 40,
        'height' => 20,
        'arguments' => array('extension' => 'jpeg'),
        'mimetype' => 'image/jpeg',
      ),
    );

    // The file metadata manager service.
    $fmdm = $this->container->get('file_metadata_manager');

    // Prepare a copy of test files.
    $this->drupalGetTestFiles('image');
    file_unmanaged_copy(drupal_get_path('module', 'imagemagick') . '/misc/test-multi-frame.gif', 'public://', FILE_EXISTS_REPLACE);

    // Perform tests with both identify and getimagesize.
    foreach (['imagemagick_identify', 'getimagesize'] as $parsing_method) {
      // Set parsing method.
      $config->set('use_identify', $parsing_method === 'imagemagick_identify')->save();

      // Perform tests without caching.
      $config->set('parse_caching.enabled', FALSE)->save();
      foreach ($files as $source_uri => $source_image_data) {
        $this->assertFalse($fmdm->has($source_uri));
        $source_image_md = $fmdm->uri($source_uri);
        $this->assertTrue($fmdm->has($source_uri));
        $first = TRUE;
        file_unmanaged_delete_recursive($this->testDirectory);
        file_prepare_directory($this->testDirectory, FILE_CREATE_DIRECTORY);
        foreach ($operations as $op => $values) {
          // Load up a fresh image.
          if ($first) {
            $this->assertIdentical(FileMetadataInterface::NOT_LOADED, $source_image_md->isMetadataLoaded($parsing_method));
          }
          else {
            $this->assertIdentical(FileMetadataInterface::LOADED_FROM_FILE, $source_image_md->isMetadataLoaded($parsing_method));
          }
          $source_image = $this->imageFactory->get($source_uri);
          $this->assertIdentical(FileMetadataInterface::LOADED_FROM_FILE, $source_image_md->isMetadataLoaded($parsing_method));
          $this->assertIdentical($source_image_data['mimetype'], $source_image->getMimeType());
          if (!isset($source_image_data['skip_dimensions_check'])) {
            $this->assertIdentical($source_image_data['height'], $source_image->getHeight());
            $this->assertIdentical($source_image_data['width'], $source_image->getWidth());
          }

          // Perform our operation.
          $source_image->apply($values['function'], $values['arguments']);

          // Save image.
          $saved_uri = $this->testDirectory . '/' . $op . substr($source_uri, -4);
          $this->assertFalse($fmdm->has($saved_uri));
          $this->assertTrue($source_image->save($saved_uri));
          if ($parsing_method === 'imagemagick_identify' && $source_image->getToolkit()->getFrames() == 1) {
            $this->assertTrue($fmdm->has($saved_uri));
          }
          else {
            $this->assertFalse($fmdm->has($saved_uri));
          }

          // Reload saved image and check data.
          $saved_image_md = $fmdm->uri($saved_uri);
          $saved_image = $this->imageFactory->get($saved_uri);
          if ($parsing_method === 'imagemagick_identify' && $saved_image->getToolkit()->getFrames() == 1  && !($values['function'] === 'convert' && $source_image_data['frames'] > 1)) {
            $this->assertIdentical(FileMetadataInterface::LOADED_BY_CODE, $saved_image_md->isMetadataLoaded($parsing_method));
          }
          else {
            $this->assertIdentical(FileMetadataInterface::LOADED_FROM_FILE, $saved_image_md->isMetadataLoaded($parsing_method));
          }
          $this->assertIdentical($values['function'] === 'convert' ? $values['mimetype'] : $source_image_data['mimetype'], $saved_image->getMimeType());
          if (!isset($source_image_data['skip_dimensions_check'])) {
            $this->assertEqual($values['height'], $saved_image->getHeight());
            $this->assertEqual($values['width'], $saved_image->getWidth());
          }
          $fmdm->release($saved_uri);

          // Get metadata via the file_mdm service.
          $saved_image_md = $fmdm->uri($saved_uri);
          // Should not be available at this stage.
          $this->assertIdentical(FileMetadataInterface::NOT_LOADED, $saved_image_md->isMetadataLoaded($parsing_method));
          // Get metadata from file.
          $metadata = $saved_image_md->getMetadata($parsing_method);
          $this->assertIdentical(FileMetadataInterface::LOADED_FROM_FILE, $saved_image_md->isMetadataLoaded($parsing_method));
          switch ($parsing_method) {
            case 'imagemagick_identify':
              if (!isset($source_image_data['skip_dimensions_check'])) {
                $this->assertEqual($values['height'], $saved_image_md->getMetadata($parsing_method, 'height'));
                $this->assertEqual($values['width'], $saved_image_md->getMetadata($parsing_method, 'width'));
              }
              break;

              case 'getimagesize':
              if (!isset($source_image_data['skip_dimensions_check'])) {
                $this->assertEqual($values['height'], $saved_image_md->getMetadata($parsing_method, 1));
                $this->assertEqual($values['width'], $saved_image_md->getMetadata($parsing_method, 0));
              }
              break;

          }
          $fmdm->release($saved_uri);

          $first = FALSE;
        }
        $fmdm->release($source_uri);
        $this->assertFalse($fmdm->has($source_uri));
      }

      // Perform tests with caching.
      $config->set('parse_caching.enabled', TRUE)->save();
      foreach ($files as $source_uri => $source_image_data) {
        $first = TRUE;
        file_unmanaged_delete_recursive($this->testDirectory);
        file_prepare_directory($this->testDirectory, FILE_CREATE_DIRECTORY);
        foreach ($operations as $op => $values) {
          // Load up a fresh image.
          $this->assertFalse($fmdm->has($source_uri));
          $source_image_md = $fmdm->uri($source_uri);
          $this->assertTrue($fmdm->has($source_uri));
          $this->assertIdentical(FileMetadataInterface::NOT_LOADED, $source_image_md->isMetadataLoaded($parsing_method));
          $source_image = $this->imageFactory->get($source_uri);
          if ($first || $parsing_method == 'getimagesize') {
            // First time load, metadata loaded from file.
            $this->assertIdentical(FileMetadataInterface::LOADED_FROM_FILE, $source_image_md->isMetadataLoaded($parsing_method));
          }
          else {
            // Further loads, metadata loaded from cache.
            $this->assertIdentical(FileMetadataInterface::LOADED_FROM_CACHE, $source_image_md->isMetadataLoaded($parsing_method));
          }
          $this->assertIdentical($source_image_data['mimetype'], $source_image->getMimeType());
          if (!isset($source_image_data['skip_dimensions_check'])) {
            $this->assertIdentical($source_image_data['height'], $source_image->getHeight());
            $this->assertIdentical($source_image_data['width'], $source_image->getWidth());
          }

          // Perform our operation.
          $source_image->apply($values['function'], $values['arguments']);

          // Save image.
          $saved_uri = $this->testDirectory . '/' . $op . substr($source_uri, -4);
          $this->assertFalse($fmdm->has($saved_uri));
          $this->assertTrue($source_image->save($saved_uri));
          if ($parsing_method === 'imagemagick_identify' && $source_image->getToolkit()->getFrames() == 1) {
            $this->assertTrue($fmdm->has($saved_uri));
          }
          else {
            $this->assertFalse($fmdm->has($saved_uri));
          }

          // Reload saved image and check data.
          $saved_image_md = $fmdm->uri($saved_uri);
          $saved_image = $this->imageFactory->get($saved_uri);
          if ($parsing_method === 'imagemagick_identify' && $saved_image->getToolkit()->getFrames() == 1  && !($values['function'] === 'convert' && $source_image_data['frames'] > 1)) {
            $this->assertIdentical(FileMetadataInterface::LOADED_BY_CODE, $saved_image_md->isMetadataLoaded($parsing_method));
          }
          else {
            $this->assertIdentical(FileMetadataInterface::LOADED_FROM_FILE, $saved_image_md->isMetadataLoaded($parsing_method));
          }
          $this->assertIdentical($values['function'] === 'convert' ? $values['mimetype'] : $source_image_data['mimetype'], $saved_image->getMimeType());
          if (!isset($source_image_data['skip_dimensions_check'])) {
            $this->assertEqual($values['height'], $saved_image->getHeight());
            $this->assertEqual($values['width'], $saved_image->getWidth());
          }
          $fmdm->release($saved_uri);

          // Get metadata via the file_mdm service. Should be cached.
          $saved_image_md = $fmdm->uri($saved_uri);
          // Should not be available at this stage.
          $this->assertIdentical(FileMetadataInterface::NOT_LOADED, $saved_image_md->isMetadataLoaded($parsing_method));
          // Get metadata from cache.
          $metadata = $saved_image_md->getMetadata($parsing_method);
          if ($parsing_method === 'imagemagick_identify') {
            $this->assertIdentical(FileMetadataInterface::LOADED_FROM_CACHE, $saved_image_md->isMetadataLoaded($parsing_method));
          }
          else {
            $this->assertIdentical(FileMetadataInterface::LOADED_FROM_FILE, $saved_image_md->isMetadataLoaded($parsing_method));
          }
          switch ($parsing_method) {
            case 'imagemagick_identify':
              if (!isset($source_image_data['skip_dimensions_check'])) {
                $this->assertEqual($values['height'], $saved_image_md->getMetadata($parsing_method, 'height'));
                $this->assertEqual($values['width'], $saved_image_md->getMetadata($parsing_method, 'width'));
              }
              break;

              case 'getimagesize':
              if (!isset($source_image_data['skip_dimensions_check'])) {
                $this->assertEqual($values['height'], $saved_image_md->getMetadata($parsing_method, 1));
                $this->assertEqual($values['width'], $saved_image_md->getMetadata($parsing_method, 0));
              }
              break;

          }
          $fmdm->release($saved_uri);

          // We release the source image FileMetadata at each cycle to ensure
          // that metadata is read from cache.
          $fmdm->release($source_uri);
          $this->assertFalse($fmdm->has($source_uri));

          $first = FALSE;
        }
      }
    }

    // Files in temporary:// must not be cached.
    $config->set('use_identify', TRUE)->save();
    file_unmanaged_copy(drupal_get_path('module', 'imagemagick') . '/misc/test-multi-frame.gif', 'temporary://', FILE_EXISTS_REPLACE);
    $source_uri = 'temporary://test-multi-frame.gif';
    $fmdm->release($source_uri);
    $source_image_md = $fmdm->uri($source_uri);
    $this->assertIdentical(FileMetadataInterface::NOT_LOADED, $source_image_md->isMetadataLoaded('imagemagick_identify'));
    $source_image = $this->imageFactory->get($source_uri);
    $this->assertIdentical(FileMetadataInterface::LOADED_FROM_FILE, $source_image_md->isMetadataLoaded('imagemagick_identify'));
    $fmdm->release($source_uri);
    $source_image_md = $fmdm->uri($source_uri);
    $source_image = $this->imageFactory->get($source_uri);
    $this->assertIdentical(FileMetadataInterface::LOADED_FROM_FILE, $source_image_md->isMetadataLoaded('imagemagick_identify'));

    // Open source images again after deleting the temp folder files.
    // Source image data should now be cached, but temp files non existing.
    // Therefore we test that the toolkit can create a new temp file copy.
    $this->assertTrue(count(file_scan_directory('temporary://', '/imagemagick*.*/') > 0));
    foreach (file_scan_directory('temporary://', '/imagemagick*.*/') as $file) {
      file_unmanaged_delete($file->uri);
    }
    $this->assertEqual(0, count(file_scan_directory('temporary://', '/imagemagick*.*/')));
    foreach (['imagemagick_identify', 'getimagesize'] as $parsing_method) {
      // Set parsing method.
      $config->set('use_identify', $parsing_method === 'imagemagick_identify')->save();
      foreach ($files as $source_uri => $source_image_data) {
        file_unmanaged_delete_recursive($this->testDirectory);
        file_prepare_directory($this->testDirectory, FILE_CREATE_DIRECTORY);
        foreach ($operations as $op => $values) {
          // Load up the source image. Parsing should be fully cached now.
          $fmdm->release($source_uri);
          $source_image_md = $fmdm->uri($source_uri);
          $this->assertIdentical(FileMetadataInterface::NOT_LOADED, $source_image_md->isMetadataLoaded($parsing_method));
          $source_image = $this->imageFactory->get($source_uri);
          if ($parsing_method === 'getimagesize') {
            // 'getimagesize', metadata loaded from file.
            $this->assertIdentical(FileMetadataInterface::LOADED_FROM_FILE, $source_image_md->isMetadataLoaded($parsing_method));
          }
          else {
            // Metadata loaded from cache.
            $this->assertIdentical(FileMetadataInterface::LOADED_FROM_CACHE, $source_image_md->isMetadataLoaded($parsing_method));
          }
          $this->assertIdentical($source_image_data['mimetype'], $source_image->getMimeType());
          if (!isset($source_image_data['skip_dimensions_check'])) {
            $this->assertIdentical($source_image_data['height'], $source_image->getHeight());
            $this->assertIdentical($source_image_data['width'], $source_image->getWidth());
          }

          // Perform our operation.
          $source_image->apply($values['function'], $values['arguments']);

          // Save image.
          $saved_uri = $this->testDirectory . '/' . $op . substr($source_uri, -4);
          $this->assertFalse($fmdm->has($saved_uri));
          $this->assertTrue($source_image->save($saved_uri));
          if ($parsing_method === 'imagemagick_identify' && $source_image->getToolkit()->getFrames() == 1) {
            $this->assertTrue($fmdm->has($saved_uri));
          }
          else {
            $this->assertFalse($fmdm->has($saved_uri));
          }

          // Reload saved image and check data.
          $saved_image_md = $fmdm->uri($saved_uri);
          $saved_image = $this->imageFactory->get($saved_uri);
          if ($parsing_method === 'imagemagick_identify' && $saved_image->getToolkit()->getFrames() == 1  && !($values['function'] === 'convert' && $source_image_data['frames'] > 1)) {
            $this->assertIdentical(FileMetadataInterface::LOADED_BY_CODE, $saved_image_md->isMetadataLoaded($parsing_method));
          }
          else {
            $this->assertIdentical(FileMetadataInterface::LOADED_FROM_FILE, $saved_image_md->isMetadataLoaded($parsing_method));
          }
          $this->assertIdentical($values['function'] === 'convert' ? $values['mimetype'] : $source_image_data['mimetype'], $saved_image->getMimeType());
          if (!isset($source_image_data['skip_dimensions_check'])) {
            $this->assertEqual($values['height'], $saved_image->getHeight());
            $this->assertEqual($values['width'], $saved_image->getWidth());
          }
          $fmdm->release($saved_uri);

          // Get metadata via the file_mdm service. Should be cached.
          $saved_image_md = $fmdm->uri($saved_uri);
          // Should not be available at this stage.
          $this->assertIdentical(FileMetadataInterface::NOT_LOADED, $saved_image_md->isMetadataLoaded($parsing_method));
          // Get metadata from cache.
          $metadata = $saved_image_md->getMetadata($parsing_method);
          if ($parsing_method === 'imagemagick_identify') {
            $this->assertIdentical(FileMetadataInterface::LOADED_FROM_CACHE, $saved_image_md->isMetadataLoaded($parsing_method));
          }
          else {
            $this->assertIdentical(FileMetadataInterface::LOADED_FROM_FILE, $saved_image_md->isMetadataLoaded($parsing_method));
          }
          switch ($parsing_method) {
            case 'imagemagick_identify':
              if (!isset($source_image_data['skip_dimensions_check'])) {
                $this->assertEqual($values['height'], $saved_image_md->getMetadata($parsing_method, 'height'));
                $this->assertEqual($values['width'], $saved_image_md->getMetadata($parsing_method, 'width'));
              }
              break;

              case 'getimagesize':
              if (!isset($source_image_data['skip_dimensions_check'])) {
                $this->assertEqual($values['height'], $saved_image_md->getMetadata($parsing_method, 1));
                $this->assertEqual($values['width'], $saved_image_md->getMetadata($parsing_method, 0));
              }
              break;

          }
          $fmdm->release($saved_uri);
        }
      }
    }

    // Invalidate cache, and open source images again. Now, all files should be
    // parsed again.
    Cache::InvalidateTags(['file_mdm:imagemagick_identify']);
    // Disallow caching on the test results directory.
    $config->set('parse_caching.disallowed_paths', ['public://imagetest/*'])->save();
    foreach ($files as $source_uri => $source_image_data) {
      $fmdm->release($source_uri);
    }
    // Perform tests with both identify and getimagesize.
    foreach (['imagemagick_identify', 'getimagesize'] as $parsing_method) {
      // Set parsing method.
      $config->set('use_identify', $parsing_method === 'imagemagick_identify')->save();
      foreach ($files as $source_uri => $source_image_data) {
        $this->assertFalse($fmdm->has($source_uri));
        $source_image_md = $fmdm->uri($source_uri);
        $this->assertTrue($fmdm->has($source_uri));
        $first = TRUE;
        file_unmanaged_delete_recursive($this->testDirectory);
        file_prepare_directory($this->testDirectory, FILE_CREATE_DIRECTORY);
        foreach ($operations as $op => $values) {
          // Load up a fresh image.
          if ($first) {
            $this->assertIdentical(FileMetadataInterface::NOT_LOADED, $source_image_md->isMetadataLoaded($parsing_method));
          }
          else {
            $this->assertIdentical(FileMetadataInterface::LOADED_FROM_FILE, $source_image_md->isMetadataLoaded($parsing_method));
          }
          $source_image = $this->imageFactory->get($source_uri);
          $this->assertIdentical(FileMetadataInterface::LOADED_FROM_FILE, $source_image_md->isMetadataLoaded($parsing_method));
          $this->assertIdentical($source_image_data['mimetype'], $source_image->getMimeType());
          if (!isset($source_image_data['skip_dimensions_check'])) {
            $this->assertIdentical($source_image_data['height'], $source_image->getHeight());
            $this->assertIdentical($source_image_data['width'], $source_image->getWidth());
          }

          // Perform our operation.
          $source_image->apply($values['function'], $values['arguments']);

          // Save image.
          $saved_uri = $this->testDirectory . '/' . $op . substr($source_uri, -4);
          $this->assertFalse($fmdm->has($saved_uri));
          $this->assertTrue($source_image->save($saved_uri));
          if ($parsing_method === 'imagemagick_identify' && $source_image->getToolkit()->getFrames() == 1) {
            $this->assertTrue($fmdm->has($saved_uri));
          }
          else {
            $this->assertFalse($fmdm->has($saved_uri));
          }

          // Reload saved image and check data.
          $saved_image_md = $fmdm->uri($saved_uri);
          $saved_image = $this->imageFactory->get($saved_uri);
          if ($parsing_method === 'imagemagick_identify' && $saved_image->getToolkit()->getFrames() == 1  && !($values['function'] === 'convert' && $source_image_data['frames'] > 1)) {
            $this->assertIdentical(FileMetadataInterface::LOADED_BY_CODE, $saved_image_md->isMetadataLoaded($parsing_method));
          }
          else {
            $this->assertIdentical(FileMetadataInterface::LOADED_FROM_FILE, $saved_image_md->isMetadataLoaded($parsing_method));
          }
          $this->assertIdentical($values['function'] === 'convert' ? $values['mimetype'] : $source_image_data['mimetype'], $saved_image->getMimeType());
          if (!isset($source_image_data['skip_dimensions_check'])) {
            $this->assertEqual($values['height'], $saved_image->getHeight());
            $this->assertEqual($values['width'], $saved_image->getWidth());
          }
          $fmdm->release($saved_uri);

          // Get metadata via the file_mdm service.
          $saved_image_md = $fmdm->uri($saved_uri);
          // Should not be available at this stage.
          $this->assertIdentical(FileMetadataInterface::NOT_LOADED, $saved_image_md->isMetadataLoaded($parsing_method));
          // Get metadata from file.
          $metadata = $saved_image_md->getMetadata($parsing_method);
          $this->assertIdentical(FileMetadataInterface::LOADED_FROM_FILE, $saved_image_md->isMetadataLoaded($parsing_method));
          switch ($parsing_method) {
            case 'imagemagick_identify':
              if (!isset($source_image_data['skip_dimensions_check'])) {
                $this->assertEqual($values['height'], $saved_image_md->getMetadata($parsing_method, 'height'));
                $this->assertEqual($values['width'], $saved_image_md->getMetadata($parsing_method, 'width'));
              }
              break;

              case 'getimagesize':
              if (!isset($source_image_data['skip_dimensions_check'])) {
                $this->assertEqual($values['height'], $saved_image_md->getMetadata($parsing_method, 1));
                $this->assertEqual($values['width'], $saved_image_md->getMetadata($parsing_method, 0));
              }
              break;

          }
          $fmdm->release($saved_uri);

          $first = FALSE;
        }
        $fmdm->release($source_uri);
        $this->assertFalse($fmdm->has($source_uri));
      }
    }
  }

}
