<?php

/**
 * Mimes
 *
 * Mime types of the images OIO optimises.
 *
 * @package     OIO
 * @author      Clément Gayot
 * @author      Ainsley Clark (Squidge, original author)
 * @category    Class
 * @repo        https://github.com/Striffly/octopus-image-optimizer
 *
 */

namespace Octopus\Types;

if (!defined('ABSPATH')) {
	exit; // Exit if accessed directly
}

abstract class Mimes
{
	const PNG = "image/png";
	const JPG = 'image/jpeg';
}
