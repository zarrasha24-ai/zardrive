"""Run in CI after `flutter create`: app name, permissions, link handling and launcher icons."""
import re, shutil, sys, pathlib
app = sys.argv[1]
root = pathlib.Path(__file__).resolve().parent.parent
proj = root / app
label = 'ZarDrive' if app == 'passenger' else 'ZarDrive Driver'
man = proj / 'android/app/src/main/AndroidManifest.xml'
s = man.read_text()
perms = ''.join(f'    <uses-permission android:name="android.permission.{p}"/>\n' for p in ['INTERNET', 'ACCESS_FINE_LOCATION', 'ACCESS_COARSE_LOCATION'])
if 'ACCESS_FINE_LOCATION' not in s:
    s = re.sub(r'(<manifest[^>]*>\n)', r'\1' + perms, s, count=1)
s = re.sub(r'android:label="[^"]*"', f'android:label="{label}"', s, count=1)
queries = ('<queries>\n        <intent><action android:name="android.intent.action.VIEW"/><data android:scheme="https"/></intent>\n'
           '        <intent><action android:name="android.intent.action.DIAL"/><data android:scheme="tel"/></intent>\n'
           '        <intent><action android:name="android.intent.action.VIEW"/><data android:scheme="tel"/></intent>\n')
if '<queries>' in s:
    s = s.replace('<queries>', queries, 1)
else:
    s = s.replace('</manifest>', '    ' + queries + '    </queries>\n</manifest>')
man.write_text(s)
res = proj / 'android/app/src/main/res'
for d in ['mdpi', 'hdpi', 'xhdpi', 'xxhdpi', 'xxxhdpi']:
    src = root / 'branding' / app / f'ic_launcher-{d}.png'
    if src.exists():
        shutil.copy(src, res / f'mipmap-{d}' / 'ic_launcher.png')
print('branded', app, '->', label)
