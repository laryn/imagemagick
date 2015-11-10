
-- SUMMARY --

Provides ImageMagick integration.

For a full description of the module, visit the project page:
  https://drupal.org/project/imagemagick
To submit bug reports and feature suggestions, or to track changes:
  https://drupal.org/project/issues/imagemagick


-- REQUIREMENTS --

* Either ImageMagick (http://www.imagemagick.org) or GraphicsMagick
  (http://www.graphicsmagick.org) need to be installed on your server
  and the convert binary needs to be accessible and executable from PHP.

* The PHP configuration must allow invocation of proc_open() (which is
  security-wise identical to exec()).

Consult your server administrator or hosting provider if you are unsure about
these requirements.


-- INSTALLATION --

* Install as usual, see https://drupal.org/node/70151 for further information.


-- CONFIGURATION --

* Go to Administration » Configuration » Media » Image toolkit and change the
  image toolkit to ImageMagick.

* Select the graphics package (ImageMagick or GraphicsMagick) you want to use
  with the toolkit.

* If the convert binary cannot be found in the default shell path, you need to
  enter the path to the executables, including the trailing slash/backslash.


-- CONTACT --

Current maintainers:
* Daniel F. Kudwien 'sun' - https://www.drupal.org/u/sun
* 'mondrake' - https://www.drupal.org/u/mondrake - for the Drupal 8 branch
  only.
