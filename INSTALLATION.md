# PHP Compress - Installation Guide

Ek simple CLI tool jo aapke PHP project ko minify karta hai. Vite jaisa - bas command run karo aur `compress` folder mein minified code mil jayega.

---

## Quick Start (Sabse Fast Tarika)

### Step 1: Download
```bash
# Download karo
git clone https://github.com/your-repo/php-compress.git

# Folder mein jao
cd php-compress
```

### Step 2: Run
```bash
# Kisi bhi project ko compress karo
php bin/compress /path/to/your/project
```

Output: `/path/to/your/project/compress` folder mein minified files milenge.

---

## Method 1: Direct PHP Use (Recommended)

Koi installation nahi, seedha use karo:

```bash
# Download
git clone https://github.com/your-repo/php-compress.git
cd php-compress

# Run
php bin/compress /path/to/project
```

---

## Method 2: Global Install (Composer)

Agar aap Composer use karte ho:

```bash
# Global install
composer global require compress/php-compress

# Ab kahi se bhi use karo
compress /path/to/project
```

---

## Usage Examples

### Basic Usage
```bash
# Project compress karo - output: project/compress/
php bin/compress /Users/rahul/my-website
```

### Custom Output Folder
```bash
# Custom output location
php bin/compress /Users/rahul/my-website /Users/rahul/minified-output
```

### Watch Mode
```bash
# Auto compress jab bhi file change ho
php bin/compress --watch /Users/rahul/my-website
```

### Minimal Output
```bash
# Sirf stats dikhao
php bin/compress --min /Users/rahul/my-website
```

### Dry Run
```bash
# Preview karo bina file likhe
php bin/compress --dry-run /Users/rahul/my-website
```

---

## Commands

| Command | Kaam |
|---------|------|
| `php bin/compress <folder>` | Compress karo |
| `php bin/compress <src> <output>` | Custom output mein compress |
| `php bin/compress --watch <folder>` | Auto compress on change |
| `php bin/compress --init` | Config file banao |
| `php bin/compress --help` | Help dekho |
| `php bin/compress --version` | Version dekho |

---

## Supported Files

| File Type | Features |
|-----------|----------|
| `.php` | PHP + inline HTML/CSS/JS minify |
| `.html` | HTML + inline CSS/JS minify |
| `.css` | CSS minify (strings safe) |
| `.js` | JavaScript minify (ES6+, regex safe) |
| `.json` | JSON minify (no precision loss) |
| `.jsx` | React JSX minify |
| `.xml` | XML minify (CDATA safe) |

---

## Output

Jab aap compress run karte ho, ye hota hai:

```
your-project/
├── index.php          ← Original
├── about.html         ← Original
├── css/
│   └── style.css      ← Original
└── compress/          ← NEW FOLDER
    ├── index.php      ← Minified + index.php added
    ├── about.html     ← Minified
    ├── css/
    │   ├── style.css  ← Minified
    │   └── index.php  ← Added automatically
    └── index.php      ← Added automatically
```

**Har folder mein `index.php` with `<?php // Silence is golden.` automatically add hota hai.**

---

## Example Output

```bash
$ php bin/compress /Users/rahul/my-project

    ╔═════════════════════════════════════════════════════╗
    ║           PHP Project Minifier v1.0.0              ║
    ╚═════════════════════════════════════════════════════╝

Source: /Users/rahul/my-project
Output: /Users/rahul/my-project/compress

╔════════════════════════════════════════════╗
║              COMPRESSION RESULTS            ║
╠════════════════════════════════════════════╣
║  Total Files:                           25  ║
║  Compressed:                            25  ║
║  Skipped:                                0  ║
║  Ignored:                                5  ║
╠════════════════════════════════════════════╣
║  Original Size:                    125 KB  ║
║  Compressed Size:                   72 KB  ║
║  Saved:                             53 KB  ║
║  Reduction:                        42.4%   ║
╚════════════════════════════════════════════╝

✓ Output: /Users/rahul/my-project/compress
```

---

## Config File (Optional)

`compress.json` banao project root mein:

```json
{
    "exclude": [
        "node_modules",
        "vendor",
        ".git",
        "*.min.js",
        "*.min.css"
    ],
    "createIndex": true
}
```

---

## Requirements

- PHP 7.4 ya higher
- Terminal access

---

## Tips

1. **First time?** Dry run se check karo: `php bin/compress --dry-run /path/to/project`

2. **Big project?** `.gitignore` waale folders automatically skip hote hain

3. **Watch mode** development ke liye best hai - file save karo aur auto compress

4. **Production deploy** se pehle compress karo, 40%+ size save hoga

---

## Troubleshooting

**Permission denied?**
```bash
chmod +x bin/compress
```

**Folder not found?**
```bash
# Full path use karo
php bin/compress /full/path/to/project
```

**Already compressed files skip karni hai?**
```json
// compress.json mein add karo
{
    "exclude": ["*.min.js", "*.min.css"]
}
```

---

Bas itna hi! Ab apne projects ko compress karo aur fast loading website banao.
