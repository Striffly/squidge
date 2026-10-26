/**
 * tools/logo/build.js
 *
 * Draws the logo, a pixel-art octopus hugging a picture, and
 * writes it to assets/images: a still PNG and a floating GIF,
 * at 128 and 256 px. Run it with `bun run logo`.
 *
 * The octopus comes from octopus.txt, and the arms it wraps
 * in front of the picture from arms.txt: 128 rows of 128
 * pixels, one letter per colour (see SPRITE). Everything
 * else is drawn here: the glow behind, the shadow on the
 * floor, the tilted picture and its halo, the stars.
 */

import {readFileSync, writeFileSync} from 'node:fs';
import {crc32, deflateSync} from 'node:zlib';
import {GIFEncoder} from 'gifenc';

const HERE = new URL('./', import.meta.url);
const IMAGES = new URL('../../assets/images/', import.meta.url);
const SIZE = 128;

const C = {
	bg: [25, 12, 55], g1: [31, 14, 66], g2: [38, 17, 78], shadow: [15, 6, 34],
	p: [251, 71, 164], pl: [252, 172, 208], pll: [255, 214, 230], blush: [251, 140, 182],
	mag: [189, 48, 165], v: [122, 57, 218], vo: [103, 66, 179], w: [255, 255, 255],
	// The outlines in the dark violet of the night, the mouth in plum, the eyes alone in black, as the original.
	k: [25, 9, 58], mouth: [42, 0, 46], eye: [8, 5, 18],
	tint: [214, 150, 236], tint2: [170, 214, 250],
	c: [0, 229, 255], cd: [0, 150, 205], cl: [200, 252, 255], cin: [0, 85, 130],
	halo1: [34, 112, 168], halo2: [28, 58, 112], glow1: [112, 62, 200], glow2: [58, 28, 118],
	s1: [196, 62, 196], s2: [240, 70, 170], s3: [255, 110, 160], s4: [255, 150, 140], s5: [255, 186, 120],
	y: [255, 226, 110], yl: [255, 247, 190],
	m1: [0, 196, 228], m2: [100, 238, 252], m3: [0, 130, 186],
};
const PINKS = ['p', 'mag', 'pl', 'pll', 'blush'];
const NB4 = [[1, 0], [-1, 0], [0, 1], [0, -1]];
const BAYER = [[0, 8, 2, 10], [12, 4, 14, 6], [3, 11, 1, 9], [15, 7, 13, 5]].map((row) => row.map((v) => v / 16));

// The letters of the sprite files.
const SPRITE = {'.': null, p: 'p', l: 'pl', L: 'pll', b: 'blush', m: 'mag', v: 'v', o: 'vo', k: 'k', q: 'mouth', e: 'eye', w: 'w', t: 'tint'};

/**
 * @param {string} name
 * @return {Array<Array<string|null>>}
 */
function readSprite(name) {
	const rows = readFileSync(new URL(name, HERE), 'utf8').split(/\r?\n/).filter(Boolean);
	return rows.map((row) => [...row].map((letter) => SPRITE[letter]));
}

const OCTO = readSprite('octopus.txt');
const ARMS = readSprite('arms.txt');

// How far each pixel is from the octopus, counting diagonals as 1: 0 on it, up to 3. Not from the arms in
// front of the picture: the glow stays behind, and around the paw out of the picture, only its outline.
const AWAY = Array.from({length: SIZE}, (_, y) => Array.from({length: SIZE}, (_, x) => (OCTO[y][x] ? 0 : 3)));
for (let d = 1; d < 3; d++) {
	const ring = [];
	for (let y = 0; y < SIZE; y++) {
		for (let x = 0; x < SIZE; x++) {
			if (AWAY[y][x] === 3 && [-1, 0, 1].some((j) => [-1, 0, 1].some((i) => AWAY[y + j]?.[x + i] === d - 1))) {
				ring.push([x, y]);
			}
		}
	}
	ring.forEach(([x, y]) => (AWAY[y][x] = d));
}

// The picture: a square tilted by atan(1/4). On the lattice a = 4y - x, b = 4x + y,
// every edge is a regular 1-in-4 staircase; 4 lattice units are about 1 px.
const X0 = 79, Y0 = 57, SIDE = 34;
const A0 = 4 * Y0 - X0, B0 = 4 * X0 + Y0;
const A1 = A0 + 4 * SIDE, B1 = B0 + 4 * SIDE;

/**
 * Distance in px to the edge of the picture: >= 0 inside, negative outside.
 */
function margin(x, y) {
	const a = 4 * y - x, b = 4 * x + y;
	return Math.min(a - A0, A1 - 1 - a, b - B0, B1 - 1 - b) / 4;
}

function lighten(rgb, k) {
	return rgb.map((c) => Math.trunc(c + (255 - c) * k));
}

/**
 * The sunset in the picture, at picture coordinates (0 to about 19).
 *
 * @param {number|null} shine Where the shine sweeping across is, or null.
 */
function pictureColour(pu, pv, shine) {
	const sky = ['s1', 's1', 's2', 's2', 's2', 's3', 's3', 's3', 's4', 's4', 's5', 's5', 's5', 's5'];
	let col = sky[Math.min(Math.max(Math.trunc(pv), 0), sky.length - 1)];
	if ((pu - 12.5) ** 2 + (pv - 7) ** 2 < 10) {
		col = (pu - 11.8) ** 2 + (pv - 6.3) ** 2 < 2.5 ? 'yl' : 'y';
	}
	const peakA = 10 + Math.abs(pu - 6) * 0.9;
	const peakB = 12 + Math.abs(pu - 15) * 0.8;
	if (pv > peakA || pv > peakB) {
		col = 'm1';
		if (pv > peakA && pu < 6 && pv < peakA + 2) {
			col = 'm2';
		} else if (pv > peakB && pu < 15 && pv < peakB + 2 && pv <= peakA) {
			col = 'm2';
		}
		if (pv > 16) {
			col = (Math.trunc(pu) + Math.trunc(pv)) % 2 === 0 || pv > 17 ? 'm3' : 'm1';
		}
	}
	// The glare that stays on the glass.
	const g = pu + pv - 9;
	if ((g >= 0 && g < 3.4) || (g >= 4.6 && g < 5.6)) {
		return lighten(C[col], 0.75);
	}
	if (shine !== null && pu + pv - shine >= 0 && pu + pv - shine < 2.2) {
		return lighten(C[col], 0.9);
	}
	return C[col];
}

const STARS = [[103, 50], [112, 57], [116, 87], [110, 99]];

/**
 * A four-pointed star: 1 is small, 2 is large, 0 is off.
 */
function drawStar(img, x, y, size) {
	if (size <= 0) {
		return;
	}
	const points = [[0, 0, 'w'], ...NB4.map(([i, j]) => [i, j, size === 1 ? 'cl' : 'w'])];
	if (size >= 2) {
		points.push(...NB4.map(([i, j]) => [2 * i, 2 * j, 'c']));
	}
	for (const [i, j, col] of points) {
		img.set(x + i, y + j, C[col]);
	}
}

class Canvas {
	constructor() {
		this.pixels = Array.from({length: SIZE * SIZE}, () => C.bg);
	}

	set(x, y, rgb) {
		if (x >= 0 && x < SIZE && y >= 0 && y < SIZE) {
			this.pixels[y * SIZE + x] = rgb;
		}
	}
}

/**
 * One frame.
 *
 * @param {number} dy How high the octopus floats, in px (0 or less).
 * @param {number|null} shine Where the shine sweeping across the picture is.
 * @param {number[]} stars The size of each star.
 * @return {Canvas}
 */
function render(dy, shine, stars) {
	const img = new Canvas();
	const put = (x, y, col) => img.set(x, y + dy, typeof col === 'string' ? C[col] : col);

	// The glow behind the octopus, in dithered steps.
	for (let y = 0; y < SIZE; y++) {
		for (let x = 0; x < SIZE; x++) {
			const t = (65 - Math.hypot(x - 61.5, (y - 60.5) * 1.1)) / 12.5;
			const th = BAYER[y % 4][x % 4];
			if (t > 1 + th) {
				img.set(x, y, C.g2);
			} else if (t > th) {
				img.set(x, y, C.g1);
			}
		}
	}
	// The shadow on the floor, smaller as the octopus floats up.
	const sw = 27.5 + dy * 2, sy = 107;
	for (let y = sy - 3; y < Math.min(sy + 4, SIZE); y++) {
		for (let x = 0; x < SIZE; x++) {
			const e = ((x - 66.5) / sw) ** 2 + ((y - sy) / 2.8) ** 2;
			if (e < 0.55 || (e < 1 && (x + y) % 2 === 0)) {
				img.set(x, y, C.shadow);
			}
		}
	}
	// The violet glow around the octopus: a ring, then a dithered one. Not by the picture, where its cyan
	// halo is alone.
	for (let y = 0; y < SIZE; y++) {
		for (let x = 0; x < SIZE; x++) {
			if (margin(x, y) >= -2) {
				continue;
			}
			if (AWAY[y][x] === 1) {
				put(x, y, 'glow1');
			} else if (AWAY[y][x] === 2 && (x + y) % 2 === 0) {
				put(x, y, 'glow2');
			}
		}
	}
	for (let y = 0; y < SIZE; y++) {
		for (let x = 0; x < SIZE; x++) {
			if (OCTO[y][x]) {
				put(x, y, OCTO[y][x]);
			}
		}
	}
	// The cyan halo around the picture, which also lights the body.
	for (let y = 0; y < SIZE; y++) {
		for (let x = 0; x < SIZE; x++) {
			const m = margin(x, y);
			if (m < -2 || m >= -0.25) {
				continue;
			}
			if (!OCTO[y][x]) {
				if (m >= -1) {
					put(x, y, 'halo1');
				} else if ((x + y) % 2 === 0) {
					put(x, y, 'halo2');
				}
			} else if (PINKS.includes(OCTO[y][x]) && m >= -1) {
				put(x, y, 'tint');
			}
		}
	}
	// The picture: a dark outline, the cyan frame lit from the top left, then the sunset.
	const k = 19 / (SIDE - 8);
	for (let y = 0; y < SIZE; y++) {
		for (let x = 0; x < SIZE; x++) {
			const a = 4 * y - x, b = 4 * x + y;
			if (a < A0 || a >= A1 || b < B0 || b >= B1) {
				continue;
			}
			const top = Math.floor((a - A0) / 4), left = Math.floor((b - B0) / 4);
			const bottom = Math.floor((A1 - 1 - a) / 4), right = Math.floor((B1 - 1 - b) / 4);
			const m = Math.min(top, left, bottom, right);
			let col;
			if (m === 0) {
				col = 'k';
			} else if (m <= 2) {
				const nearTopLeft = Math.min(top, left) <= 2 && Math.min(bottom, right) > 2;
				const nearBottomRight = Math.min(bottom, right) <= 2 && Math.min(top, left) > 2;
				col = nearTopLeft && m === 1 ? 'cl' : (nearBottomRight && m === 2 ? 'cd' : 'c');
			} else if (m === 3) {
				col = 'cin';
			} else {
				col = pictureColour(((b - B0) / 4.12 - 3.6) * k, ((a - A0) / 4.12 - 3.6) * k, shine);
			}
			put(x, y, col);
		}
	}
	// The arms in front of the picture, then their outline over it.
	for (let y = 0; y < SIZE; y++) {
		for (let x = 0; x < SIZE; x++) {
			if (ARMS[y][x]) {
				put(x, y, ['pl', 'pll'].includes(ARMS[y][x]) && margin(x, y) >= -1 ? 'tint2' : ARMS[y][x]);
			}
		}
	}
	for (let y = 1; y < SIZE - 1; y++) {
		for (let x = 1; x < SIZE - 1; x++) {
			// Not next to an outline of the arms already drawn, as around the paw.
			if (!ARMS[y][x] && margin(x, y) >= -1 && NB4.some(([i, j]) => ARMS[y + j][x + i] && ARMS[y + j][x + i] !== 'k')) {
				put(x, y, 'k');
			}
		}
	}
	// The stars twinkle in place: they do not float.
	STARS.forEach(([x, y], i) => drawStar(img, x, y, stars[i]));
	return img;
}

// 40 frames of 80 ms. The octopus floats 3 px up at a steady pace, a little longer at the top and bottom.
const FRAMES = 40;
const DELAY = 80;
const FLOAT = [...Array(8).fill(0), ...Array(6).fill(-1), ...Array(6).fill(-2), ...Array(8).fill(-3), ...Array(6).fill(-2), ...Array(6).fill(-1)];
const TWINKLE = [0, 0, 1, 1, 2, 2, 2, 1, 1, 0, ...Array(10).fill(0)];

function frames() {
	return Array.from({length: FRAMES}, (_, f) => render(
		FLOAT[f],
		f >= 8 && f <= 20 ? -6 + (f - 8) * 4 : null,
		STARS.map((_, i) => TWINKLE[(f + i * 5) % TWINKLE.length]),
	));
}

/**
 * The colours of the frames, and each frame as indexes into them, scaled up by an integer.
 *
 * @param {Canvas[]} canvases
 * @param {number} scale
 */
function indexed(canvases, scale) {
	const palette = [], lookup = new Map();
	const size = SIZE * scale;
	const images = canvases.map((img) => {
		const index = new Uint8Array(size * size);
		for (let y = 0; y < size; y++) {
			for (let x = 0; x < size; x++) {
				const rgb = img.pixels[Math.floor(y / scale) * SIZE + Math.floor(x / scale)];
				const key = rgb.join();
				if (!lookup.has(key)) {
					lookup.set(key, palette.length);
					palette.push(rgb);
				}
				index[y * size + x] = lookup.get(key);
			}
		}
		return index;
	});
	if (palette.length > 256) {
		throw new Error(`The logo has ${palette.length} colours, a GIF holds 256.`);
	}
	return {palette, images, size};
}

function chunk(type, data) {
	const body = Buffer.concat([Buffer.from(type, 'latin1'), data]);
	const length = Buffer.alloc(4), crc = Buffer.alloc(4);
	length.writeUInt32BE(data.length);
	crc.writeUInt32BE(crc32(body));
	return Buffer.concat([length, body, crc]);
}

/**
 * An indexed PNG: a few kilobytes for a few dozen colours.
 */
function png(canvas, scale) {
	const {palette, images: [index], size} = indexed([canvas], scale);
	const header = Buffer.alloc(13);
	header.writeUInt32BE(size, 0);
	header.writeUInt32BE(size, 4);
	header.set([8, 3, 0, 0, 0], 8); // 8 bits, indexed colour
	const raw = Buffer.alloc(size * (size + 1));
	for (let y = 0; y < size; y++) {
		raw.set(index.subarray(y * size, (y + 1) * size), y * (size + 1) + 1); // filter 0 on each row
	}
	return Buffer.concat([
		Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
		chunk('IHDR', header),
		chunk('PLTE', Buffer.from(palette.flat())),
		chunk('IDAT', deflateSync(Buffer.from(raw), {level: 9})),
		chunk('IEND', Buffer.alloc(0)),
	]);
}

/**
 * A GIF that loops forever. A frame the same as the one before only lengthens it,
 * and each frame leaves what did not change transparent over the one before.
 */
function gif(canvases, scale) {
	const {palette, images, size} = indexed(canvases, scale);
	if (palette.length > 255) {
		throw new Error(`The logo has ${palette.length} colours, a GIF holds 255 and the transparent one.`);
	}
	const unchanged = palette.length;
	const runs = [];
	images.forEach((index) => {
		const last = runs.at(-1);
		if (last && Buffer.compare(last.index, index) === 0) {
			last.delay += DELAY;
		} else {
			runs.push({index, delay: DELAY});
		}
	});
	const encoder = GIFEncoder();
	runs.forEach(({index, delay}, i) => {
		const previous = i > 0 ? runs[i - 1].index : null;
		const pixels = previous ? index.map((value, p) => (value === previous[p] ? unchanged : value)) : index;
		encoder.writeFrame(pixels, size, size, {
			palette: i === 0 ? [...palette, [0, 0, 0]] : undefined,
			delay,
			repeat: 0,
			transparent: Boolean(previous),
			transparentIndex: unchanged,
			dispose: 1, // keep the frame before under the transparent pixels
		});
	});
	encoder.finish();
	return Buffer.from(encoder.bytes());
}

const still = render(0, null, [2, 1, 2, 1]);
const animation = frames();
for (const scale of [1, 2]) {
	const name = `icon-${SIZE * scale}x${SIZE * scale}`;
	writeFileSync(new URL(`${name}.png`, IMAGES), png(still, scale));
	writeFileSync(new URL(`${name}.gif`, IMAGES), gif(animation, scale));
	console.log(`assets/images/${name}.png, assets/images/${name}.gif`);
}
