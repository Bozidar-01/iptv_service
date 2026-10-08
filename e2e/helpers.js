import { createHmac } from 'node:crypto';

export const ADMIN = { username: 'admin', password: 'e2e-password' };

export async function signIn(page, { username, password } = ADMIN) {
    await page.goto('/login');
    await page.getByLabel('Username').fill(username);
    await page.getByLabel('Password').fill(password);
    await page.getByRole('button', { name: 'Sign in' }).click();
}

const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

function base32Decode(text) {
    let bits = '';
    for (const char of text.toUpperCase().replace(/=+$/, '')) {
        bits += ALPHABET.indexOf(char).toString(2).padStart(5, '0');
    }
    const bytes = [];
    for (let i = 0; i + 8 <= bits.length; i += 8) bytes.push(parseInt(bits.slice(i, i + 8), 2));
    return Buffer.from(bytes);
}

/** The 6-digit authenticator code for a secret; `stepOffset` 1 = the next 30 seconds (the server accepts one step of drift). */
export function totp(secret, stepOffset = 0) {
    const step = Math.floor(Date.now() / 30000) + stepOffset;
    const counter = Buffer.alloc(8);
    counter.writeBigUInt64BE(BigInt(step));
    const hash = createHmac('sha1', base32Decode(secret)).update(counter).digest();
    const offset = hash[19] & 0xf;
    const number = ((hash[offset] & 0x7f) << 24) | (hash[offset + 1] << 16) | (hash[offset + 2] << 8) | hash[offset + 3];
    return String(number % 1_000_000).padStart(6, '0');
}
