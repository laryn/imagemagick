<?php

/**
 * @file
 * Definition of Drupal\imagemagick\Tests\ToolkitImagemagickTest.
 */

namespace Drupal\imagemagick\Tests;

use Drupal\Component\Utility\SafeMarkup;
use Drupal\Core\Image\ImageInterface;
use Drupal\simpletest\WebTestBase;

/**
 * Tests that core image manipulations work properly through Imagemagick.
 *
 * @group Imagemagick
 */
class ToolkitImagemagickTest extends WebTestBase {

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

  // Colors that are used in testing.
  protected $black       = array(0, 0, 0, 0);
  protected $red         = array(255, 0, 0, 0);
  protected $green       = array(0, 255, 0, 0);
  protected $blue        = array(0, 0, 255, 0);
  protected $yellow      = array(255, 255, 0, 0);
  protected $white       = array(255, 255, 255, 0);
  protected $transparent = array(0, 0, 0, 127);
  // Used as rotate background colors.
  protected $fuchsia            = array(255, 0, 255, 0);
  protected $rotateTransparent = array(255, 255, 255, 127);

  protected $width = 40;
  protected $height = 20;

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = array('system', 'simpletest', 'imagemagick');

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

    // Change the toolkit.
    \Drupal::configFactory()->getEditable('system.image')
      ->set('toolkit', 'imagemagick')
      ->save();
    \Drupal::configFactory()->getEditable('imagemagick.settings')
      ->set('debug', TRUE)
      ->set('quality', 100)
      ->save();

    // Set the toolkit on the image factory.
    $this->imageFactory = $this->container->get('image.factory');
    $this->imageFactory->setToolkitId('imagemagick');

    // Prepare a directory for test file results.
    $this->testDirectory = 'public://imagetest';
    file_prepare_directory($this->testDirectory, FILE_CREATE_DIRECTORY);
  }

  /**
   * Function to compare two colors by RGBa.
   */
  protected function colorsAreEqual($color_a, $color_b) {
    // Fully transparent pixels are equal, regardless of RGB.
    if ($color_a[3] == 127 && $color_b[3] == 127) {
      return TRUE;
    }

    $distance = pow(($color_a[0] - $color_b[0]), 2) + pow(($color_a[1] - $color_b[1]), 2) + pow(($color_a[2] - $color_b[2]), 2);
    foreach ($color_a as $key => $value) {
      if ($color_b[$key] != $value && $distance > 100) {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Function for finding a pixel's RGBa values.
   */
  protected function getPixelColor(ImageInterface $image, $x, $y) {
    $toolkit = $image->getToolkit();
    $color_index = imagecolorat($toolkit->getResource(), $x, $y);

    $transparent_index = imagecolortransparent($toolkit->getResource());
    if ($color_index == $transparent_index) {
      return array(0, 0, 0, 127);
    }

    return array_values(imagecolorsforindex($toolkit->getResource(), $color_index));
  }

  /**
   * Test image toolkit operations.
   *
   * Since PHP can't visually check that our images have been manipulated
   * properly, build a list of expected color values for each of the corners and
   * the expected height and widths for the final images.
   */
  public function testManipulations() {
    // Test that the image factory is set to use the Imagemagick toolkit.
    $this->assertEqual($this->imageFactory->getToolkitId(), 'imagemagick', 'The image factory is set to use the \'imagemagick\' image toolkit.');

    // The test can only be executed if the ImageMagick 'convert' is
    // available on the shell path.
    $status = \Drupal::service('image.toolkit.manager')->createInstance('imagemagick')->checkPath('');
    if (!empty($status['errors'])) {
      // This pass is here only to allow automated test on d.o. not to set the
      // entire branch to failure. Bots do not have ImageMagick installed, so
      // there's no purpose to try and run this test there; it can be run
      // locally where ImageMagick is installed.
      $this->pass('Image manipulations for the Imagemagick toolkit cannot run because the \'convert\' executable is not available.');
      return;
    }

    // Typically the corner colors will be unchanged. These colors are in the
    // order of top-left, top-right, bottom-right, bottom-left.
    $default_corners = array($this->red, $this->green, $this->blue, $this->transparent);

    // A list of files that will be tested.
    $files = array(
      'image-test.png',
      'image-test.gif',
      'image-test-no-transparency.gif',
      'image-test.jpg',
    );

    // Setup a list of tests to perform on each type.
    $operations = array(
      'resize' => array(
        'function' => 'resize',
        'arguments' => array('width' => 20, 'height' => 10),
        'width' => 20,
        'height' => 10,
        'corners' => $default_corners,
      ),
      'scale_x' => array(
        'function' => 'scale',
        'arguments' => array('width' => 20),
        'width' => 20,
        'height' => 10,
        'corners' => $default_corners,
      ),
      'scale_y' => array(
        'function' => 'scale',
        'arguments' => array('height' => 10),
        'width' => 20,
        'height' => 10,
        'corners' => $default_corners,
      ),
      'upscale_x' => array(
        'function' => 'scale',
        'arguments' => array('width' => 80, 'upscale' => TRUE),
        'width' => 80,
        'height' => 40,
        'corners' => $default_corners,
      ),
      'upscale_y' => array(
        'function' => 'scale',
        'arguments' => array('height' => 40, 'upscale' => TRUE),
        'width' => 80,
        'height' => 40,
        'corners' => $default_corners,
      ),
      'crop' => array(
        'function' => 'crop',
        'arguments' => array('x' => 12, 'y' => 4, 'width' => 16, 'height' => 12),
        'width' => 16,
        'height' => 12,
        'corners' => array_fill(0, 4, $this->white),
      ),
      'scale_and_crop' => array(
        'function' => 'scale_and_crop',
        'arguments' => array('width' => 10, 'height' => 8),
        'width' => 10,
        'height' => 8,
        'corners' => array_fill(0, 4, $this->black),
      ),
      'convert_jpg' => array(
        'function' => 'convert',
        'width' => 40,
        'height' => 20,
        'arguments' => array('extension' => 'jpeg'),
        'mimetype' => 'image/jpeg',
        'corners' => $default_corners,
      ),
      'convert_gif' => array(
        'function' => 'convert',
        'width' => 40,
        'height' => 20,
        'arguments' => array('extension' => 'gif'),
        'mimetype' => 'image/gif',
        'corners' => $default_corners,
      ),
      'convert_png' => array(
        'function' => 'convert',
        'width' => 40,
        'height' => 20,
        'arguments' => array('extension' => 'png'),
        'mimetype' => 'image/png',
        'corners' => $default_corners,
      ),
      'rotate_5' => array(
        'function' => 'rotate',
        'arguments' => array('degrees' => 5, 'background' => '#FF00FF'), // Fuchsia background.
        'width' => 42,
        'height' => 24,
        'corners' => array_fill(0, 4, $this->fuchsia),
      ),
      'rotate_minus_10' => array(
        'function' => 'rotate',
        'arguments' => array('degrees' => -10, 'background' => '#FF00FF'), // Fuchsia background.
        'width' => 43,
        'height' => 27,
        'corners' => array_fill(0, 4, $this->fuchsia),
      ),
      'rotate_90' => array(
        'function' => 'rotate',
        'arguments' => array('degrees' => 90, 'background' => '#FF00FF'), // Fuchsia background.
        'width' => 20,
        'height' => 40,
        'corners' => array($this->transparent, $this->red, $this->green, $this->blue),
      ),
      'rotate_transparent_5' => array(
        'function' => 'rotate',
        'arguments' => array('degrees' => 5),
        'width' => 42,
        'height' => 24,
        'corners' => array_fill(0, 4, $this->transparent),
      ),
      'rotate_transparent_90' => array(
        'function' => 'rotate',
        'arguments' => array('degrees' => 90),
        'width' => 20,
        'height' => 40,
        'corners' => array($this->transparent, $this->red, $this->green, $this->blue),
      ),
      'desaturate' => array(
        'function' => 'desaturate',
        'arguments' => array(),
        'height' => 20,
        'width' => 40,
        // Grayscale corners are a bit funky. Each of the corners are a shade of
        // gray. The values of these were determined simply by looking at the
        // final image to see what desaturated colors end up being.
        'corners' => array(
          array_fill(0, 3, 76) + array(3 => 0),
          array_fill(0, 3, 149) + array(3 => 0),
          array_fill(0, 3, 29) + array(3 => 0),
          array_fill(0, 3, 225) + array(3 => 127),
        ),
      ),
    );

    // Prepare a copy of test files.
    $this->drupalGetTestFiles('image');

    foreach ($files as $file) {
      foreach ($operations as $op => $values) {
        // Load up a fresh image.
        $image = $this->imageFactory->get('public://' . $file);
        if (!$image->isValid()) {
          $this->fail(SafeMarkup::format('Could not load image %file.', array('%file' => $file)));
          continue 2;
        }

        // Perform our operation.
        $image->apply($values['function'], $values['arguments']);

        // Save image.
        $file_path = $this->testDirectory . '/' . $op . substr($file, -4);
        $image->save($file_path);

        // Reload with GD to be able to check results at pixel level.
        $image = $this->imageFactory->get($file_path, 'gd');
        $toolkit = $image->getToolkit();

        // Check MIME type if needed.
        if (isset($values['mimetype'])) {
          $this->assertEqual($values['mimetype'], $toolkit->getMimeType(), SafeMarkup::format('Image %file after %action action has proper MIME type (@mimetype).', array('%file' => $file, '%action' => $op, '@ew' => $values['width'], '@mimetype' => $values['mimetype'])));
        }

        // To keep from flooding the test with assert values, make a general
        // value for whether each group of values fail.
        $correct_dimensions_real = TRUE;
        $correct_dimensions_object = TRUE;

        // Check the real dimensions of the image first.
        if (imagesy($toolkit->getResource()) != $values['height'] || imagesx($toolkit->getResource()) != $values['width']) {
          $correct_dimensions_real = FALSE;
        }

        // Check that the image object has an accurate record of the dimensions.
        if ($image->getWidth() != $values['width'] || $image->getHeight() != $values['height']) {
          $correct_dimensions_object = FALSE;
        }

        $this->assertTrue($correct_dimensions_real, SafeMarkup::format('Image %file after %action action has proper dimensions. Expected @ewx@eh, actual @awx@ah.', array('%file' => $file, '%action' => $op, '@ew' => $values['width'], '@eh' => $values['height'], '@aw' => imagesx($toolkit->getResource()), '@ah' => imagesy($toolkit->getResource()))));
        $this->assertTrue($correct_dimensions_object, SafeMarkup::format('Image %file object after %action action is reporting the proper height and width values.  Expected @ewx@eh, actual @awx@ah.', array('%file' => $file, '%action' => $op, '@ew' => $values['width'], '@eh' => $values['height'], '@aw' => imagesx($toolkit->getResource()), '@ah' => imagesy($toolkit->getResource()))));

        // JPEG colors will always be messed up due to compression.
        if ($image->getToolkit()->getType() != IMAGETYPE_JPEG) {
          // Now check each of the corners to ensure color correctness.
          foreach ($values['corners'] as $key => $corner) {
            // The test gif that does not have transparency has yellow where the
            // others have transparent.
            if ($file === 'image-test-no-transparency.gif' && $corner === $this->transparent && $op != 'rotate_transparent_5') {
              $corner = $this->yellow;
            }
            // The test jpg when converted to other formats has yellow where the
            // others have transparent.
            if ($file === 'image-test.jpg' && $corner === $this->transparent && in_array($op, ['convert_gif', 'convert_png'])) {
              $corner = $this->yellow;
            }
            // Get the location of the corner.
            switch ($key) {
              case 0:
                $x = 0;
                $y = 0;
                break;

              case 1:
                $x = $image->getWidth() - 1;
                $y = 0;
                break;

              case 2:
                $x = $image->getWidth() - 1;
                $y = $image->getHeight() - 1;
                break;

              case 3:
                $x = 0;
                $y = $image->getHeight() - 1;
                break;

            }
            $color = $this->getPixelColor($image, $x, $y);
            $correct_colors = $this->colorsAreEqual($color, $corner);
            $this->assertTrue($correct_colors, SafeMarkup::format('Image %file object after %action action has the correct color placement at corner %corner.', array('%file' => $file, '%action' => $op, '%corner' => $key)));
          }
        }
      }
    }

    // Test creation of image from scratch, and saving to storage.
    foreach (array(IMAGETYPE_PNG, IMAGETYPE_GIF, IMAGETYPE_JPEG) as $type) {
      $image = $this->imageFactory->get();
      $image->createNew(50, 20, image_type_to_extension($type, FALSE), '#ffff00');
      $file = 'from_null' . image_type_to_extension($type);
      $file_path = $this->testDirectory . '/' . $file;
      $this->assertEqual(50, $image->getWidth(), SafeMarkup::format('Image file %file has the correct width.', array('%file' => $file)));
      $this->assertEqual(20, $image->getHeight(), SafeMarkup::format('Image file %file has the correct height.', array('%file' => $file)));
      $this->assertEqual(image_type_to_mime_type($type), $image->getMimeType(), SafeMarkup::format('Image file %file has the correct MIME type.', array('%file' => $file)));
      $this->assertTrue($image->save($file_path), SafeMarkup::format('Image %file created anew from a null image was saved.', array('%file' => $file)));

      // Reload saved image.
      $image_reloaded = $this->imageFactory->get($file_path, 'gd');
      if (!$image_reloaded->isValid()) {
        $this->fail(SafeMarkup::format('Could not load image %file.', array('%file' => $file)));
        continue;
      }
      $this->assertEqual(50, $image_reloaded->getWidth(), SafeMarkup::format('Image file %file has the correct width.', array('%file' => $file)));
      $this->assertEqual(20, $image_reloaded->getHeight(), SafeMarkup::format('Image file %file has the correct height.', array('%file' => $file)));
      $this->assertEqual(image_type_to_mime_type($type), $image_reloaded->getMimeType(), SafeMarkup::format('Image file %file has the correct MIME type.', array('%file' => $file)));
      if ($image_reloaded->getToolkit()->getType() == IMAGETYPE_GIF) {
        $this->assertEqual('#ffff00', $image_reloaded->getToolkit()->getTransparentColor(), SafeMarkup::format('Image file %file has the correct transparent color channel set.', array('%file' => $file)));
      }
      else {
        $this->assertEqual(NULL, $image_reloaded->getToolkit()->getTransparentColor(), SafeMarkup::format('Image file %file has no color channel set.', array('%file' => $file)));
      }
    }

    // Test failures of CreateNew.
    $image = $this->imageFactory->get();
    $image->createNew(-50, 20);
    $this->assertFalse($image->isValid(), 'CreateNew with negative width fails.');
    $image->createNew(50, 20, 'foo');
    $this->assertFalse($image->isValid(), 'CreateNew with invalid extension fails.');
    $image->createNew(50, 20, 'gif', '#foo');
    $this->assertFalse($image->isValid(), 'CreateNew with invalid color hex string fails.');
    $image->createNew(50, 20, 'gif', '#ff0000');
    $this->assertTrue($image->isValid(), 'CreateNew with valid arguments validates the Image.');

    // Test saving image files with filenames having non-ascii characters.

    $file_names = [
      'greek εικόνα δοκιμής.png',
      'russian Тестовое изображение.png',
      'simplified chinese 测试图片.png',
      'japanese 試験画像.png',
      'arabic صورة الاختبار.png',
      'armenian փորձարկման պատկերը.png',
      'bengali পরীক্ষা ইমেজ.png',
      'hebraic תמונת בדיקה.png',
      'hindi परीक्षण छवि.png',
      'viet hình ảnh thử nghiệm.png',
      'viet \'with quotes\' hình ảnh thử nghiệm.png',
      'viet "with double quotes" hình ảnh thử nghiệm.png',
    ];
    foreach ($file_names as $file) {
      $file_path = $this->testDirectory . '/' . $file;
      $image->save($file_path);
      $image_reloaded = $this->imageFactory->get($file_path);
      $this->assertTrue($image_reloaded->isValid(), SafeMarkup::format('Image file %file loaded successfully.', array('%file' => $file)));
    }

    // Test retrieval of EXIF information.

    // The image files that will be tested.
    $image_files = [
      [
        'path' => drupal_get_path('module', 'imagemagick') . '/misc/test-exif.jpeg',
        'orientation' => 8,
      ],
      [
        'path' => 'public://image-test.jpg',
        'orientation' => NULL,
      ],
      [
        'path' => 'public://image-test.png',
        'orientation' => NULL,
      ],
      [
        'path' => 'public://image-test.gif',
        'orientation' => NULL,
      ],
      [
        'path' => NULL,
        'orientation' => NULL,
      ],
    ];

    foreach($image_files as $image_file) {
      // Get image using 'identify'.
      \Drupal::configFactory()->getEditable('imagemagick.settings')
        ->set('use_identify', TRUE)
        ->save();
      $image = $this->imageFactory->get($image_file['path']);
      $this->assertIdentical($image_file['orientation'], $image->getToolkit()->getExifOrientation());

      // Get image using 'getimagesize'.
      \Drupal::configFactory()->getEditable('imagemagick.settings')
        ->set('use_identify', FALSE)
        ->save();
      $image = $this->imageFactory->get($image_file['path']);
      $this->assertIdentical($image_file['orientation'], $image->getToolkit()->getExifOrientation());
    }
  }

}
