/**
 * tools/brand/build.js
 *
 * Draws the logo, a pixel-art octopus hugging a picture, and
 * writes it to assets/images: a still PNG and a floating GIF,
 * at 128 and 256 px, and the banner of WordPress.org, at
 * 772x250 and 1544x500. Run it with `bun run brand`.
 *
 * The octopus comes from sprites/octopus.txt, and the arms
 * it wraps in front of the picture from sprites/arms.txt:
 * 128 rows of 128 pixels, one letter per colour (see
 * SPRITE). The rest is drawn here: the glow behind, the
 * shadow on the floor, the tilted picture and its halo, the
 * stars, the night of the banner, and its texts, smooth over
 * its pixels, in the display font of the page (Unbounded).
 */

import {readFileSync, writeFileSync} from 'node:fs';
import {crc32, deflateSync} from 'node:zlib';
import {createCanvas, GlobalFonts} from '@napi-rs/canvas';
import {GIFEncoder} from 'gifenc';

const SPRITES = new URL('./sprites/', import.meta.url);
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
	const rows = readFileSync(new URL(name, SPRITES), 'utf8').split(/\r?\n/).filter(Boolean);
	return rows.map((row) => [...row].map((letter) => SPRITE[letter]));
}

const OCTO = readSprite('octopus.txt');
const ARMS = readSprite('arms.txt');

GlobalFonts.registerFromPath(new URL('../../assets/fonts/unbounded-variable.woff2', import.meta.url).pathname, 'Unbounded');

/**
 * Lines of text in Unbounded, left aligned: how much each pixel is inked (0 to 255), cropped to the ink,
 * at MASK_SCALE times the grid of the banner.
 *
 * @param {string[]} lines
 * @param {number} size In px of the grid of the banner, half its 772x250.
 * @param {number} weight
 * @param {number} [leading] From one line to the next, in sizes.
 * @return {{width: number, height: number, data: Uint8Array}}
 */
function textMask(lines, size, weight, leading = 1.35) {
	const px = size * MASK_SCALE, pad = 2 * MASK_SCALE, step = Math.round(px * leading);
	const font = `${weight} ${px}px Unbounded`;
	const probe = createCanvas(1, 1).getContext('2d');
	probe.font = font;
	const canvas = createCanvas(Math.ceil(Math.max(...lines.map((line) => probe.measureText(line).width))) + 2 * pad, step * lines.length + 2 * pad);
	const ctx = canvas.getContext('2d');
	ctx.font = font;
	ctx.fillStyle = '#fff';
	ctx.textBaseline = 'top';
	lines.forEach((line, i) => ctx.fillText(line, pad, pad + i * step));
	const {data, width, height} = ctx.getImageData(0, 0, canvas.width, canvas.height);
	let top = height, bottom = -1, left = width, right = -1;
	for (let y = 0; y < height; y++) {
		for (let x = 0; x < width; x++) {
			if (data[(y * width + x) * 4 + 3]) {
				top = Math.min(top, y);
				bottom = Math.max(bottom, y);
				left = Math.min(left, x);
				right = Math.max(right, x);
			}
		}
	}
	const mask = {width: right - left + 1, height: bottom - top + 1};
	mask.data = new Uint8Array(mask.width * mask.height);
	for (let y = 0; y < mask.height; y++) {
		for (let x = 0; x < mask.width; x++) {
			mask.data[y * mask.width + x] = data[((top + y) * width + left + x) * 4 + 3];
		}
	}
	return mask;
}

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
	constructor(width = SIZE, height = SIZE) {
		this.width = width;
		this.height = height;
		this.pixels = Array.from({length: width * height}, () => C.bg);
	}

	set(x, y, rgb) {
		if (x >= 0 && x < this.width && y >= 0 && y < this.height) {
			this.pixels[y * this.width + x] = rgb;
		}
	}
}

/**
 * How much the glow behind the octopus lights a pixel, from its centre in the scene: above 1, twice.
 */
function glow(x, y) {
	return (65 - Math.hypot(x - 61.5, (y - 60.5) * 1.1)) / 12.5;
}

/**
 * The shadow on the floor, smaller as the octopus floats up, for a scene at ox, oy.
 */
function drawShadow(img, ox, oy, dy) {
	const sw = 27.5 + dy * 2, sy = 107;
	for (let y = sy - 3; y < sy + 4; y++) {
		for (let x = 0; x < SIZE; x++) {
			const e = ((x - 66.5) / sw) ** 2 + ((y - sy) / 2.8) ** 2;
			if (e < 0.55 || (e < 1 && (x + y) % 2 === 0)) {
				img.set(ox + x, oy + y, C.shadow);
			}
		}
	}
}

/**
 * The icon: one frame.
 *
 * @param {number} dy How high the octopus floats, in px (0 or less).
 * @param {number|null} shine Where the shine sweeping across the picture is.
 * @param {number[]} stars The size of each star.
 * @return {Canvas}
 */
function render(dy, shine, stars) {
	const img = new Canvas();
	// The glow behind the octopus, in dithered steps.
	for (let y = 0; y < SIZE; y++) {
		for (let x = 0; x < SIZE; x++) {
			const t = glow(x, y), th = BAYER[y % 4][x % 4];
			if (t > 1 + th) {
				img.set(x, y, C.g2);
			} else if (t > th) {
				img.set(x, y, C.g1);
			}
		}
	}
	drawShadow(img, 0, 0, dy);
	drawScene(img, 0, 0, dy, shine, stars);
	return img;
}

/**
 * The octopus hugging its picture, with the stars, its 128 px square at ox, oy.
 */
function drawScene(img, ox, oy, dy, shine, stars) {
	const put = (x, y, col) => img.set(ox + x, oy + y + dy, typeof col === 'string' ? C[col] : col);

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
	STARS.forEach(([x, y], i) => drawStar(img, ox + x, oy + y, stars[i]));
}

// The banner, drawn at half its 772x250: the night of the header of the page (tokens.css), the texts on the left.
const BANNER = {width: 386, height: 125, scene: [254, 1]};
const NIGHT = [[27, 11, 59], [34, 12, 68], [42, 14, 79], [51, 15, 95], [61, 17, 112], [72, 20, 128], [84, 24, 144]];
// The texts as in the header of the page, drawn at the 1544x500 of the banner: OIO in the gradient of the
// logo, the name in white, the tagline in cyan.
const MASK_SCALE = 4;
const TEXTS = [
	{mask: textMask(['OIO'], 36, 800), colour: (u) => [255, 60, 172].map((c, i) => Math.round(c + ([123, 47, 247][i] - c) * u))},
	{mask: textMask(['Octopus Image Optimizer'], 13, 600), colour: () => [236, 230, 250]},
	{mask: textMask(['Squeezes images. Spills no ink, sends no bill.'], 8, 500), colour: () => C.c},
];

// What OIO is about, under the tagline: a pixel icon on a round chip, and its label. The icons are drawn
// right on the grid of the banner, in the CHIP px of their disc, one drawing by colour, each pixel inked or
// not: their strokes, 1 px wide on the half px, fill whole pixels.
const ICONS = {
	server: {
		c: (ctx) => {
			ctx.beginPath();
			ctx.roundRect(3.5, 3.5, 8, 8, 1);
			ctx.moveTo(3.5, 7.5);
			ctx.lineTo(11.5, 7.5);
			ctx.stroke();
		},
		w: (ctx) => [5, 9].forEach((y) => ctx.fillRect(5, y, 1, 1)),
		l: (ctx) => [5, 9].forEach((y) => ctx.fillRect(7, y, 2, 1)),
	},
	lock: {
		c: (ctx) => {
			ctx.beginPath();
			ctx.moveTo(5.5, 7.5);
			ctx.arc(7.5, 5.5, 2, Math.PI, 0);
			ctx.lineTo(9.5, 7.5);
			ctx.stroke();
			ctx.beginPath();
			ctx.roundRect(3.5, 7.5, 8, 4, 1);
			ctx.stroke();
		},
		l: (ctx) => {
			ctx.fillRect(4, 8, 7, 3);
			// The keyhole, out of the fill.
			ctx.clearRect(7, 8, 1, 2);
		},
	},
	image: {
		c: (ctx) => {
			ctx.beginPath();
			ctx.roundRect(3.5, 4.5, 8, 6, 1);
			ctx.stroke();
		},
		l: (ctx) => {
			ctx.beginPath();
			ctx.moveTo(4, 10);
			ctx.lineTo(6.5, 7);
			ctx.lineTo(8, 8.5);
			ctx.lineTo(9, 7.5);
			ctx.lineTo(11, 9.5);
			ctx.lineTo(11, 10);
			ctx.closePath();
			ctx.fill();
		},
		w: (ctx) => ctx.fillRect(8, 6, 2, 1),
	},
};
const ICON_COLOURS = {c: C.c, l: C.m2, w: C.w};

/**
 * The pixels of an icon of the chips, over the CHIP px of its disc: those inked at least half by each of
 * its drawings, the last colour over the others.
 *
 * @param {Object<string, function(CanvasRenderingContext2D): void>} drawings
 * @return {Array<[number, number, number[]]>} x, y and colour.
 */
function iconPixels(drawings) {
	return Object.entries(drawings).flatMap(([letter, draw]) => {
		const ctx = createCanvas(CHIP, CHIP).getContext('2d');
		ctx.fillStyle = '#fff';
		ctx.strokeStyle = '#fff';
		ctx.lineWidth = 1;
		draw(ctx);
		const {data} = ctx.getImageData(0, 0, CHIP, CHIP);
		return [...Array(CHIP * CHIP).keys()].filter((p) => data[p * 4 + 3] >= 128).map((p) => [p % CHIP, Math.floor(p / CHIP), ICON_COLOURS[letter]]);
	});
}
const FEATURES = [['server', ['Runs on', 'your server']], ['lock', ['Nothing', 'sent away']], ['image', ['WebP &', 'AVIF']]];
const CHIPS = [];

// The title, the name, the tagline and the chips, one under the other from x 28, the space above each in GAPS,
// and the whole centred in the height of the banner.
const LEFT = 28, GAPS = [0, 7, 6, 14], CHIP = 15;
const heights = [...TEXTS.map(({mask}) => mask.height / MASK_SCALE), CHIP];
let blockY = Math.round((BANNER.height - heights.reduce((sum, h, i) => sum + h + GAPS[i], 0)) / 2);
TEXTS.forEach((text, i) => {
	blockY += GAPS[i];
	text.at = [LEFT, Math.round(blockY)];
	blockY += heights[i];
});
const chipY = Math.round(blockY + GAPS[3]);
let chipX = LEFT;
for (const [icon, lines] of FEATURES) {
	const mask = textMask(lines, 5.5, 500, 1.3);
	CHIPS.push({pixels: iconPixels(ICONS[icon]), at: [chipX, chipY]});
	TEXTS.push({mask, at: [chipX + CHIP + 4, chipY + Math.round((CHIP - mask.height / MASK_SCALE) / 2)], colour: () => [215, 205, 240]});
	chipX += CHIP + 4 + Math.ceil(mask.width / MASK_SCALE) + 10;
}
const SKY = [[8, 12], [62, 13], [131, 21], [178, 9], [233, 26], [248, 112], [142, 116], [41, 117], [206, 118], [11, 72], [96, 6], [300, 8]];

function banner() {
	const img = new Canvas(BANNER.width, BANNER.height);
	const [ox, oy] = BANNER.scene;
	for (let y = 0; y < BANNER.height; y++) {
		for (let x = 0; x < BANNER.width; x++) {
			// At 120deg as in CSS: night, then night-2 at 55 %, night-3 at the end; lighter by the glow behind the octopus.
			const t = (x * 0.866 + y * 0.5) / (BANNER.width * 0.866 + BANNER.height * 0.5);
			const level = t < 0.55 ? (t / 0.55) * 2 : 2 + ((t - 0.55) / 0.45) * 2;
			const th = BAYER[y % 4][x % 4], g = glow(x - ox, y - oy);
			const lit = Math.floor(level + th) + (g > th ? 1 : 0) + (g > 1 + th ? 1 : 0);
			img.set(x, y, NIGHT[Math.min(NIGHT.length - 1, lit)]);
		}
	}
	SKY.forEach(([x, y]) => img.set(x, y, [150, 130, 210]));
	drawStar(img, 200, 14, 1);
	drawStar(img, 247, 30, 1);
	// The chips: a disc a little lighter than the night, ringed in violet, the icon on it; a line between two.
	CHIPS.forEach(({pixels, at: [cx, cy]}, n) => {
		for (let j = 0; j < 15; j++) {
			for (let i = 0; i < 15; i++) {
				const d = Math.hypot(i - 7, j - 7);
				if (d <= 6.6) {
					img.set(cx + i, cy + j, NIGHT[5]);
				} else if (d <= 7.4) {
					img.set(cx + i, cy + j, C.glow2);
				}
			}
		}
		pixels.forEach(([x, y, colour]) => img.set(cx + x, cy + y, colour));
		if (n > 0) {
			for (let j = 3; j < 12; j++) {
				img.set(cx - 5, cy + j, NIGHT[4]);
			}
		}
	});
	drawShadow(img, ox, oy, 0);
	drawScene(img, ox, oy, 0, null, [2, 1, 2, 1]);
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
	const width = canvases[0].width * scale, height = canvases[0].height * scale;
	const images = canvases.map((img) => {
		const index = new Uint8Array(width * height);
		for (let y = 0; y < height; y++) {
			for (let x = 0; x < width; x++) {
				const rgb = img.pixels[Math.floor(y / scale) * img.width + Math.floor(x / scale)];
				const key = rgb.join();
				if (!lookup.has(key)) {
					lookup.set(key, palette.length);
					palette.push(rgb);
				}
				index[y * width + x] = lookup.get(key);
			}
		}
		return index;
	});
	if (palette.length > 256) {
		throw new Error(`The image has ${palette.length} colours, an indexed one holds 256.`);
	}
	return {palette, images, width, height};
}

/**
 * The banner, scaled up by an integer, with its texts over it: a truecolour PNG, the smooth texts having
 * more colours than an indexed one holds.
 */
function bannerPng(canvas, scale) {
	const width = canvas.width * scale, height = canvas.height * scale;
	const rgb = new Uint8Array(width * height * 3);
	for (let y = 0; y < height; y++) {
		for (let x = 0; x < width; x++) {
			rgb.set(canvas.pixels[Math.floor(y / scale) * canvas.width + Math.floor(x / scale)], (y * width + x) * 3);
		}
	}
	// Each pixel of the banner takes the mean of the mask under it.
	const k = MASK_SCALE / scale;
	for (const {mask, at: [tx, ty], colour} of TEXTS) {
		const columns = Math.floor(mask.width / k);
		for (let my = 0; my < Math.floor(mask.height / k); my++) {
			for (let mx = 0; mx < columns; mx++) {
				let sum = 0;
				for (let j = 0; j < k; j++) {
					for (let i = 0; i < k; i++) {
						sum += mask.data[(my * k + j) * mask.width + mx * k + i];
					}
				}
				const alpha = sum / (k * k * 255);
				if (!alpha || tx * scale + mx >= width || ty * scale + my >= height) {
					continue;
				}
				const p = ((ty * scale + my) * width + tx * scale + mx) * 3;
				colour(mx / columns).forEach((c, i) => (rgb[p + i] = Math.round(rgb[p + i] + (c - rgb[p + i]) * alpha)));
			}
		}
	}
	return encodePng(width, height, 2, null, rgb, width * 3);
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
	const {palette, images: [index], width, height} = indexed([canvas], scale);
	return encodePng(width, height, 3, palette, index, width);
}

/**
 * @param {number} type 3 indexed, with its palette; 2 truecolour.
 * @param {Uint8Array} data The rows one after another, of rowBytes each.
 */
function encodePng(width, height, type, palette, data, rowBytes) {
	const header = Buffer.alloc(13);
	header.writeUInt32BE(width, 0);
	header.writeUInt32BE(height, 4);
	header.set([8, type, 0, 0, 0], 8); // 8 bits
	const raw = Buffer.alloc(height * (rowBytes + 1));
	for (let y = 0; y < height; y++) {
		raw.set(data.subarray(y * rowBytes, (y + 1) * rowBytes), y * (rowBytes + 1) + 1); // filter 0 on each row
	}
	return Buffer.concat([
		Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]),
		chunk('IHDR', header),
		...(palette ? [chunk('PLTE', Buffer.from(palette.flat()))] : []),
		chunk('IDAT', deflateSync(Buffer.from(raw), {level: 9})),
		chunk('IEND', Buffer.alloc(0)),
	]);
}

/**
 * A GIF that loops forever. A frame the same as the one before only lengthens it,
 * and each frame leaves what did not change transparent over the one before.
 */
function gif(canvases, scale) {
	const {palette, images, width, height} = indexed(canvases, scale);
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
		encoder.writeFrame(pixels, width, height, {
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
const wide = banner();
for (const scale of [2, 4]) {
	const name = `banner-${BANNER.width * scale}x${BANNER.height * scale}`;
	writeFileSync(new URL(`${name}.png`, IMAGES), bannerPng(wide, scale));
	console.log(`assets/images/${name}.png`);
}
