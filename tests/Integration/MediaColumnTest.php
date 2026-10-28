<?php

/**
 * MediaColumnTest
 *
 * The actions of OIO in the media library: optimising
 * an image through the queue of the media library, and
 * the state MediaQueue.js renders.
 *
 * @package     OIO
 * @author      Clément Gayot
 * @category    Tests
 * @repo        https://github.com/Striffly/octopus-image-optimizer
 *
 */

namespace Octopus\Tests\Integration;

use Octopus\Admin\MediaColumn;
use Octopus\Media\Files;
use Octopus\Media\Queue;
use Octopus\Media\Status;
use Octopus\Tests\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use WPAjaxDieContinueException;
use WPAjaxDieStopException;

class MediaColumnTest extends TestCase
{
	/**
	 * The queue, without its request to the site: the
	 * test runs it.
	 *
	 * @var Queue
	 */
	private $queue;

	public function set_up()
	{
		parent::set_up();
		$this->queue = Queue::instance();
		$property = new ReflectionProperty($this->queue, 'identifier');
		$property->setAccessible(true);
		$identifier = $property->getValue($this->queue);
		add_filter($identifier . '_pre_dispatch', '__return_true');
		add_filter($identifier . '_wp_die', '__return_false');
	}

	public function tear_down()
	{
		$this->queue->delete_all();
		unset($_REQUEST['_ajax_nonce'], $_POST['id'], $_POST['ids']);
		parent::tear_down();
	}

	public function test_an_image_waits_in_the_queue_then_is_optimised()
	{
		$this->require_libraries('cwebp');
		$id = $this->upload_unoptimised('photo.jpg');

		$state = $this->request('ajax_optimise', ['id' => $id]);

		$this->assertTrue($state['success']);
		$this->assertTrue($state['data']['pending']);
		$this->assertStringContainsString('octopus-status-label--waiting', $state['data']['cell']);
		$this->assertSame([$id], $this->queue->ids());

		$this->run_queue();

		$this->assertSame(Status::OPTIMISED, Status::of($id));
		$this->assertFileExists(Files::variant(get_attached_file($id), '.webp'));
		$state = $this->request('ajax_state', ['ids' => (string)$id]);
		$this->assertFalse($state['data'][0]['pending']);
		$this->assertStringContainsString('Optimise again', $state['data'][0]['actions']);
		$this->assertStringContainsString('Restore original', $state['data'][0]['actions']);
	}

	public function test_an_image_is_queued_once()
	{
		$id = $this->upload_unoptimised('photo.jpg');

		$this->request('ajax_optimise', ['id' => $id]);
		$this->request('ajax_optimise', ['id' => $id]);

		$this->assertSame([$id], $this->queue->ids());
	}

	public function test_an_image_of_another_author_is_refused()
	{
		$id = $this->upload_unoptimised('photo.jpg');

		$this->assertFalse($this->request('ajax_optimise', ['id' => $id], 'author')['success']);
		$this->assertSame([], $this->queue->ids());
	}

	public function test_the_state_is_only_given_for_the_images_the_user_may_edit()
	{
		$id = $this->upload_unoptimised('photo.jpg');

		$this->assertSame([], $this->request('ajax_state', ['ids' => (string)$id], 'author')['data']);
		$this->assertCount(1, $this->request('ajax_state', ['ids' => $id . ',' . $id])['data'], 'Each image once.');
	}

	/**
	 * Sends a request of MediaQueue.js, as a user.
	 *
	 * @param string $method The method of MediaColumn.
	 * @param array $data
	 * @param string $role
	 * @return array The JSON response.
	 */
	private function request($method, $data, $role = 'administrator')
	{
		wp_set_current_user(self::factory()->user->create(['role' => $role]));
		if (is_multisite() && $role === 'administrator') {
			grant_super_admin(get_current_user_id());
		}
		$_REQUEST['_ajax_nonce'] = wp_create_nonce(MediaColumn::NONCE);
		$_POST = array_merge($_POST, $data);
		add_filter('wp_doing_ajax', '__return_true');
		add_filter('wp_die_ajax_handler', function () {
			return function ($message) {
				if ($message === -1 || $message === '-1') {
					throw new WPAjaxDieStopException('-1');
				}
				throw new WPAjaxDieContinueException('');
			};
		});
		ob_start();
		try {
			(new MediaColumn())->$method();
		} catch (WPAjaxDieContinueException $e) {
			// wp_send_json() ends the request.
		}
		return json_decode(ob_get_clean(), true);
	}

	/**
	 * Runs the queue, as its background request does.
	 */
	private function run_queue()
	{
		$handle = new ReflectionMethod($this->queue, 'handle');
		$handle->setAccessible(true);
		$handle->invoke($this->queue);
		$this->assertSame([], $this->queue->ids());
	}
}
