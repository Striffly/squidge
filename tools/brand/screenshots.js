/**
 * tools/brand/screenshots.js
 *
 * Dresses the screenshots of WordPress.org: each capture of
 * the admin, 1408x880 at 2x, rounded and shadowed on the
 * night of the header of the page, written to
 * .wordpress-org/screenshot-1.png and on, in the order given.
 *
 * bun tools/brand/screenshots.js overview.png optimise.png ...
 */

import {writeFileSync} from 'node:fs';
import {createCanvas, loadImage} from '@napi-rs/canvas';

const WORDPRESS_ORG = new URL('../../.wordpress-org/', import.meta.url);
const WIDTH = 2400, HEIGHT = 1560, SHOT = 2160, RADIUS = 28;

/**
 * @param {string} path A capture.
 * @return {Promise<Buffer>} The dressed PNG.
 */
async function dress(path) {
	const capture = await loadImage(path);
	const canvas = createCanvas(WIDTH, HEIGHT);
	const ctx = canvas.getContext('2d');

	// The night of the header (tokens.css), at 120deg, and a pink glow from the top right corner.
	const night = ctx.createLinearGradient(0, 0, WIDTH, WIDTH * 0.577);
	night.addColorStop(0, '#1b0b3b');
	night.addColorStop(0.55, '#2a0e4f');
	night.addColorStop(1, '#3d1170');
	ctx.fillStyle = night;
	ctx.fillRect(0, 0, WIDTH, HEIGHT);
	const glow = ctx.createRadialGradient(WIDTH, 0, 0, WIDTH, 0, WIDTH * 0.7);
	glow.addColorStop(0, 'rgb(255 60 172 / 28%)');
	glow.addColorStop(0.45, 'rgb(123 47 247 / 12%)');
	glow.addColorStop(1, 'rgb(123 47 247 / 0%)');
	ctx.fillStyle = glow;
	ctx.fillRect(0, 0, WIDTH, HEIGHT);

	// The capture, centred, rounded, on its shadow.
	const height = Math.round((capture.height / capture.width) * SHOT);
	const x = (WIDTH - SHOT) / 2, y = Math.round((HEIGHT - height) / 2);
	ctx.save();
	ctx.shadowColor = 'rgb(8 3 20 / 55%)';
	ctx.shadowBlur = 60;
	ctx.shadowOffsetY = 24;
	ctx.fillStyle = '#000';
	ctx.beginPath();
	ctx.roundRect(x, y, SHOT, height, RADIUS);
	ctx.fill();
	ctx.restore();
	ctx.save();
	ctx.beginPath();
	ctx.roundRect(x, y, SHOT, height, RADIUS);
	ctx.clip();
	ctx.drawImage(capture, x, y, SHOT, height);
	ctx.restore();
	ctx.strokeStyle = 'rgb(255 255 255 / 14%)';
	ctx.lineWidth = 2;
	ctx.beginPath();
	ctx.roundRect(x + 1, y + 1, SHOT - 2, height - 2, RADIUS - 1);
	ctx.stroke();

	return canvas.encode('png');
}

for (const [i, path] of process.argv.slice(2).entries()) {
	const name = `screenshot-${i + 1}.png`;
	writeFileSync(new URL(name, WORDPRESS_ORG), await dress(path));
	console.log(`.wordpress-org/${name}`);
}
