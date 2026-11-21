"""Run in CI after `flutter create`: app name, permissions, link handling and launcher icons."""
import re, shutil, sys, pathlib
app = sys.argv[1]
root = pathlib.Path(__file__).resolve().parent.parent
proj = root / app
label = sys.argv[2] if len(sys.argv) > 2 and sys.argv[2] else ('ZarDrive' if app == 'passenger' else 'ZarDrive Driver')
app_id = sys.argv[3] if len(sys.argv) > 3 else ''
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
# Google's native map (free inside the app): the key comes from the GitHub secret GOOGLE_MAPS_KEY, never from the code
import os
gkey = os.environ.get('GOOGLE_MAPS_KEY', '').strip()
if gkey and 'com.google.android.geo.API_KEY' not in s:
    s = s.replace('<application', '<application', 1)
    s = re.sub(r'(<application[^>]*>)', r'\1\n        <meta-data android:name="com.google.android.geo.API_KEY" android:value="' + gkey + '"/>', s, count=1)
man.write_text(s)
res = proj / 'android/app/src/main/res'
for d in ['mdpi', 'hdpi', 'xhdpi', 'xxhdpi', 'xxxhdpi']:
    src = root / 'branding' / app / f'ic_launcher-{d}.png'
    if src.exists():
        shutil.copy(src, res / f'mipmap-{d}' / 'ic_launcher.png')
# flutter create adds a sample test for a counter app we do not have
sample = proj / 'test' / 'widget_test.dart'
if sample.exists():
    sample.unlink()
# Android's release lint step crashes inside the build tools on some plugin sources; it checks nothing of ours
for name in ['build.gradle.kts', 'build.gradle']:
    g = proj / 'android' / name
    if g.exists():
        t = g.read_text()
        if 'lintVital' not in t:
            t += '\nsubprojects {\n    tasks.matching { it.name.contains("lintVital") }.configureEach { enabled = false }\n}\n'
            g.write_text(t)
        break
# a different application id lets the Zarrasha rides apps sit beside the ZarDrive apps on one phone
if app_id:
    for name in ['build.gradle.kts', 'build.gradle']:
        g = proj / 'android' / 'app' / name
        if g.exists():
            t = g.read_text()
            t = re.sub(r'applicationId\s*=\s*"[^"]+"', f'applicationId = "{app_id}"', t)
            t = re.sub(r'applicationId\s+"[^"]+"', f'applicationId "{app_id}"', t)
            g.write_text(t)
            break
print('branded', app, '->', label, app_id)
