import { readFile } from 'node:fs/promises'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'
import sharp from 'sharp'

const here = dirname(fileURLToPath(import.meta.url))
const source = resolve(here, '..', 'public', 'favicon.svg')

const SIZE = 1024
const svg = await readFile(source)
const { data, info } = await sharp(svg, { density: SIZE * 4 })
  .resize(SIZE, SIZE)
  .removeAlpha()
  .raw()
  .toBuffer({ resolveWithObject: true })

const channels = info.channels
let minX = SIZE, maxX = -1, minY = SIZE, maxY = -1

for (let y = 0; y < SIZE; y++) {
  for (let x = 0; x < SIZE; x++) {
    const i = (y * SIZE + x) * channels
    const r = data[i], g = data[i + 1], b = data[i + 2]
    if (r > 180 && g > 180 && b > 180) {
      if (x < minX) minX = x
      if (x > maxX) maxX = x
      if (y < minY) minY = y
      if (y > maxY) maxY = y
    }
  }
}

const toVB = (px) => (px / SIZE) * 32

const leftPx = minX
const rightPx = SIZE - 1 - maxX
const topPx = minY
const bottomPx = SIZE - 1 - maxY

console.log('--- mark bounding box in pixels (1024x1024) ---')
console.log({ minX, maxX, minY, maxY, width: maxX - minX, height: maxY - minY })
console.log('--- padding in pixels ---')
console.log({ left: leftPx, right: rightPx, top: topPx, bottom: bottomPx })
console.log('--- padding in viewBox units (32x32) ---')
console.log({
  left: +toVB(leftPx).toFixed(3),
  right: +toVB(rightPx).toFixed(3),
  top: +toVB(topPx).toFixed(3),
  bottom: +toVB(bottomPx).toFixed(3),
})
console.log('--- mark center in viewBox units ---')
console.log({
  cx: +toVB((minX + maxX) / 2).toFixed(3),
  cy: +toVB((minY + maxY) / 2).toFixed(3),
  offsetX: +(toVB((minX + maxX) / 2) - 16).toFixed(3),
  offsetY: +(toVB((minY + maxY) / 2) - 16).toFixed(3),
})
