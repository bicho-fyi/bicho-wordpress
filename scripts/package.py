#!/usr/bin/env python3
import shutil
from pathlib import Path, PurePosixPath
from zipfile import ZIP_DEFLATED, ZipFile, ZipInfo

ROOT = Path(__file__).resolve().parent.parent
REQUIRED = (
    'bicho-wordpress.php', 'uninstall.php', 'readme.txt', 'LICENSE',
    'ACKNOWLEDGEMENTS.md', 'includes', 'dist',
)


def safe_path(name):
    path = PurePosixPath(name)
    if path.is_absolute() or '..' in path.parts or '\\' in name:
        raise ValueError(f'Unsafe package path: {name}')
    if any(part.startswith('.') or part in ('node_modules', '_temp') for part in path.parts) or path.parts[0] == 'vendor':
        raise ValueError(f'Unexpected package path: {name}')
    return path


def package():
    for name in REQUIRED:
        if not (ROOT / name).exists():
            raise FileNotFoundError(f'Missing runtime path: {name}; build the SDKs first')
    for name in ('bicho.min.js', 'browser.js', 'index.js', 'manifest.json', 'vendor/bicho-php/Client.php',
                 'PHP-LICENSE', 'PHP-ACKNOWLEDGEMENTS', 'JS-LICENSE', 'JS-ACKNOWLEDGEMENTS'):
        if not (ROOT / 'dist' / name).is_file():
            raise FileNotFoundError(f'Missing SDK artifact: dist/{name}; run npm run build')
    paths = []
    for name in (*REQUIRED, 'languages'):
        source = ROOT / name
        if not source.exists():
            continue
        candidates = [source, *sorted(source.rglob('*'))] if source.is_dir() else [source]
        for path in candidates:
            if path.is_symlink():
                raise ValueError(f'Symlinks are not allowed: {path.relative_to(ROOT)}')
            safe_path(path.relative_to(ROOT).as_posix())
            if path.is_file():
                paths.append(path)
    stage = ROOT / 'build' / 'bicho-error-tracking'
    if stage.exists():
        shutil.rmtree(stage)
    stage.mkdir(parents=True)
    for path in paths:
        target = stage / path.relative_to(ROOT)
        if path.name == 'ACKNOWLEDGEMENTS.md' and path.parent == ROOT:
            target = stage / 'ACKNOWLEDGEMENTS.txt'
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copyfile(path, target)
        target.chmod(0o644)
    archive = ROOT / 'build' / 'bicho-error-tracking.zip'
    with ZipFile(archive, 'w', compression=ZIP_DEFLATED, compresslevel=9) as output:
        for path in sorted(stage.rglob('*')):
            if path.is_file():
                name = 'bicho-error-tracking/' + path.relative_to(stage).as_posix()
                safe_path(name)
                info = ZipInfo(name, date_time=(1980, 1, 1, 0, 0, 0))
                info.create_system = 3
                info.external_attr = 0o100644 << 16
                info.compress_type = ZIP_DEFLATED
                output.writestr(info, path.read_bytes(), compresslevel=9)
    with ZipFile(archive) as output:
        if output.testzip() is not None or len(output.namelist()) != len(paths):
            raise ValueError('ZIP verification failed')
        for name in output.namelist():
            safe_path(name)
            if PurePosixPath(name).parts[0] != 'bicho-error-tracking':
                raise ValueError('ZIP must have exactly one plugin root')
    print(f'Packaged {len(paths)} runtime files: {archive.relative_to(ROOT)}')


if __name__ == '__main__':
    safe_path('bicho-wordpress/dist/bicho.min.js')
    for invalid in ('../escape', '/absolute', 'dist/../../escape', 'dist\\escape', '.env'):
        try:
            safe_path(invalid)
        except ValueError:
            continue
        raise AssertionError(f'Unsafe path accepted: {invalid}')
    package()
