<?php

/**
 * PictureTest
 *
 * The <picture> elements offering the AVIF and WebP
 * versions of the images to the browsers.
 *
 * @package     OIO
 * @author      Clément Gayot
 * @repo        https://github.com/Striffly/octopus-image-optimizer
 *
 */

namespace Octopus\Tests\Integration;

use Octopus\Delivery\Picture;
use Octopus\Tests\TestCase;

class PictureTest extends TestCase
{
	/**
	 * The URL of the uploads folder.
	 *
	 * @var string
	 */
	private $url;

	/**
	 * Makes the files of an image: photo.jpg with its
	 * WebP and AVIF versions, and a thumbnail with its
	 * WebP version only (its AVIF was heavier).
	 */
	public function set_up()
	{
		parent::set_up();
		$this->url = wp_get_upload_dir()['baseurl'];
		wp_mkdir_p($this->uploads . '/2026/10');
		wp_mkdir_p($this->uploads . '/octopus/formats/2026/10');
		foreach (['2026/10/photo.jpg', 'octopus/formats/2026/10/photo.jpg.webp', 'octopus/formats/2026/10/photo.jpg.avif', '2026/10/photo-300x200.jpg', 'octopus/formats/2026/10/photo-300x200.jpg.webp', '2026/10/other.jpg'] as $file) {
			touch($this->uploads . '/' . $file);
		}
	}

	public function test_the_image_is_wrapped_with_its_avif_and_webp_versions()
	{
		$html = Picture::render($this->img());

		$this->assertSame(
			'<picture class="octopus-picture">'
			. '<source type="image/avif" srcset="' . $this->url . '/octopus/formats/2026/10/photo.jpg.avif 640w, ' . $this->url . '/2026/10/photo-300x200.jpg 300w" sizes="(max-width: 640px) 100vw, 640px">'
			. '<source type="image/webp" srcset="' . $this->url . '/octopus/formats/2026/10/photo.jpg.webp 640w, ' . $this->url . '/octopus/formats/2026/10/photo-300x200.jpg.webp 300w" sizes="(max-width: 640px) 100vw, 640px">'
			. '<img data-octopus src="' . $this->url . '/2026/10/photo.jpg" srcset="' . $this->url . '/2026/10/photo.jpg 640w, ' . $this->url . '/2026/10/photo-300x200.jpg 300w" sizes="(max-width: 640px) 100vw, 640px" alt="A photo">'
			. '</picture>',
			$html
		);
	}

	public function test_an_image_is_wrapped_once()
	{
		$html = Picture::render($this->img());

		$this->assertSame($html, Picture::render($html));
	}

	public function test_the_text_around_the_image_is_kept()
	{
		$html = Picture::render('<figure>' . $this->img() . '<figcaption>Caption</figcaption></figure>');

		$this->assertStringStartsWith('<figure><picture class="octopus-picture"><source', $html);
		$this->assertStringEndsWith('</picture><figcaption>Caption</figcaption></figure>', $html);
	}

	public function test_an_image_without_versions_is_not_wrapped()
	{
		$img = '<img src="' . $this->url . '/2026/10/other.jpg" alt="">';

		// Marked as done, so that it is not looked up again.
		$this->assertSame('<img data-octopus src="' . $this->url . '/2026/10/other.jpg" alt="">', Picture::render($img));
		$this->assertSame('<picture class="octopus-picture hero"><img data-octopus src="' . $this->url . '/2026/10/other.jpg" alt=""></picture>', Picture::render($img, 'hero'), 'Unless the <picture> is asked for, with its class.');
	}

	public function test_a_file_added_next_to_the_image_is_not_offered()
	{
		// E.g. uploaded to the media library under this name, by any author.
		touch($this->uploads . '/2026/10/other.jpg.avif');
		$img = '<img src="' . $this->url . '/2026/10/other.jpg" alt="">';

		$this->assertSame('<img data-octopus src="' . $this->url . '/2026/10/other.jpg" alt="">', Picture::render($img));
	}

	public function test_an_attribute_is_never_read_inside_another_one()
	{
		// E.g. written by a contributor in the alternative text.
		$img = '<img alt=" srcset=\'' . $this->url . '/2026/10/photo-300x200.jpg 1x\' > here" src="' . $this->url . '/2026/10/photo.jpg">';

		$html = Picture::render($img);

		$this->assertSame(
			'<picture class="octopus-picture">'
			. '<source type="image/avif" srcset="' . $this->url . '/octopus/formats/2026/10/photo.jpg.avif">'
			. '<source type="image/webp" srcset="' . $this->url . '/octopus/formats/2026/10/photo.jpg.webp">'
			. '<img data-octopus alt=" srcset=\'' . $this->url . '/2026/10/photo-300x200.jpg 1x\' > here" src="' . $this->url . '/2026/10/photo.jpg">'
			. '</picture>',
			$html,
			'Its srcset is the one of the src, and its > does not end the tag.'
		);
	}

	public function test_an_endless_srcset_is_left_alone()
	{
		$srcset = implode(', ', array_map(function ($width) {
			return $this->url . '/2026/10/photo.jpg ' . $width . 'w';
		}, range(1, Picture::MAX_CANDIDATES + 1)));
		$img = '<img src="' . $this->url . '/2026/10/photo.jpg" srcset="' . $srcset . '" alt="">';

		$this->assertSame($img, Picture::render($img));
		$this->assertSame([], octopus_image_sources($srcset));
	}

	public function test_the_files_of_oio_are_left_alone()
	{
		$img = '<img src="' . $this->url . '/octopus/formats/2026/10/photo.jpg.webp" alt="">';

		$this->assertSame($img, Picture::render($img));
	}

	public function test_the_images_out_of_the_uploads_folder_are_left_alone()
	{
		$images = [
			'<img src="https://cdn.example.com/2026/10/photo.jpg" alt="">',
			'<img src="' . home_url('/wp-content/themes/theme/photo.jpg') . '" alt="">',
			'<img src="' . $this->url . '/2026/10/../10/photo.jpg" alt="">',
			'<p>No image</p>',
		];
		foreach ($images as $img) {
			$this->assertSame($img, Picture::render($img));
		}
	}

	public function test_the_query_string_and_the_scheme_do_not_matter()
	{
		$src = preg_replace('#^https?:#', 'https:', $this->url) . '/2026/10/photo.jpg?ver=2';
		$html = Picture::render('<img src="' . $src . '" alt="">');

		$this->assertStringContainsString('<source type="image/avif" srcset="' . preg_replace('#^https?:#', 'https:', $this->url) . '/octopus/formats/2026/10/photo.jpg.avif">', $html);
	}

	public function test_the_images_of_the_site_are_wrapped_when_enabled()
	{
		$id = $this->attachment();

		$this->assertStringNotContainsString('<picture', wp_get_attachment_image($id, 'full'));
		$this->assertStringNotContainsString('<picture', apply_filters('the_content', $this->img()));

		self::set('serve_picture', true);
		$this->assertStringStartsWith('<picture class="octopus-picture">', wp_get_attachment_image($id, 'full'));
		$this->assertStringContainsString('<picture class="octopus-picture">', apply_filters('the_content', '<p>' . $this->img(' class="wp-image-' . $id . '"') . '</p>'));
	}

	public function test_the_images_of_the_admin_are_not_wrapped()
	{
		$id = $this->attachment();
		self::set('serve_picture', true);
		set_current_screen('upload');

		$this->assertStringNotContainsString('<picture', wp_get_attachment_image($id, 'full'));
	}

	public function test_octopus_image_always_offers_the_versions()
	{
		$id = $this->attachment();

		$html = octopus_image($id, 'hero');
		$this->assertStringStartsWith('<picture class="octopus-picture hero"><source type="image/avif"', $html);

		$lazy = octopus_image($id, '', true);
		$this->assertStringContainsString(' data-srcset="', $lazy);
		$this->assertStringContainsString(' data-src="', $lazy);
		$this->assertDoesNotMatchRegularExpression('/\s(src|srcset)=/', $lazy);
	}

	public function test_octopus_image_sources_returns_the_srcset_of_each_format()
	{
		$srcset = $this->url . '/2026/10/photo.jpg 640w, ' . $this->url . '/2026/10/photo-300x200.jpg 300w';

		$this->assertSame(
			[
				'image/avif' => $this->url . '/octopus/formats/2026/10/photo.jpg.avif 640w, ' . $this->url . '/2026/10/photo-300x200.jpg 300w',
				'image/webp' => $this->url . '/octopus/formats/2026/10/photo.jpg.webp 640w, ' . $this->url . '/octopus/formats/2026/10/photo-300x200.jpg.webp 300w',
			],
			octopus_image_sources($srcset)
		);
	}

	public function test_octopus_image_sources_takes_a_single_url()
	{
		$this->assertSame(['image/webp' => $this->url . '/octopus/formats/2026/10/photo-300x200.jpg.webp'], octopus_image_sources($this->url . '/2026/10/photo-300x200.jpg'));
		$this->assertSame([], octopus_image_sources($this->url . '/2026/10/other.jpg'), 'An image without versions has no source.');
		$this->assertSame([], octopus_image_sources('https://cdn.example.com/2026/10/photo.jpg'), 'Nor an image out of the uploads folder.');
		$this->assertSame([], octopus_image_sources(''));
	}

	/**
	 * Returns the <img> of the photo, as WordPress
	 * writes it.
	 *
	 * @param string $attributes
	 * @return string
	 */
	private function img($attributes = '')
	{
		return '<img' . $attributes . ' src="' . $this->url . '/2026/10/photo.jpg" srcset="' . $this->url . '/2026/10/photo.jpg 640w, ' . $this->url . '/2026/10/photo-300x200.jpg 300w" sizes="(max-width: 640px) 100vw, 640px" alt="A photo">';
	}

	/**
	 * Returns an attachment of the photo, with its
	 * thumbnail.
	 *
	 * @return int
	 */
	private function attachment()
	{
		$id = self::factory()->attachment->create_object(['file' => '2026/10/photo.jpg', 'post_mime_type' => 'image/jpeg']);
		wp_update_attachment_metadata($id, [
			'width' => 640,
			'height' => 427,
			'file' => '2026/10/photo.jpg',
			'sizes' => ['medium' => ['file' => 'photo-300x200.jpg', 'width' => 300, 'height' => 200, 'mime-type' => 'image/jpeg']],
		]);
		return $id;
	}
}
