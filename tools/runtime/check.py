#!/usr/bin/env python3
"""Real transport contract checks, standard library only; servers started separately."""
import argparse
import atexit
import http.client
import json
import os
import pathlib
import time
import urllib.parse

parser = argparse.ArgumentParser()
parser.add_argument('--url', required=True)
parser.add_argument('--state-dir', type=pathlib.Path, required=True)
parser.add_argument('--worker', action='store_true')
parser.add_argument('--probes', type=int, default=os.environ.get('PHP_HONO_TEST_PROBES'))
parser.add_argument('--single-boot', action='store_true', default=os.environ.get('PHP_HONO_TEST_SINGLE_BOOT') == '1')
args = parser.parse_args()
probes = args.probes if args.probes is not None else (100 if args.worker else 5)
if probes < 1:
    parser.error('--probes must be positive')
if args.single_boot and not args.worker:
    parser.error('--single-boot requires --worker')
url = urllib.parse.urlsplit(args.url)
state = args.state_dir
request_count = 0
request_connection = http.client.HTTPConnection(url.hostname, url.port, timeout=10)
atexit.register(request_connection.close)


def request(path, method='GET', body=None, headers=None):
    global request_count
    request_connection.request(method, path, body, headers or {})
    response = request_connection.getresponse()
    data = response.read()
    result = response.status, response.getheaders(), data
    request_count += 1
    return result


def wait_file(name):
    deadline = time.monotonic() + 5
    while not (state / name).exists():
        if time.monotonic() > deadline:
            raise AssertionError(f'Missing cleanup marker: {name}')
        time.sleep(.01)


for attempt in range(100):
    try:
        if request('/')[0] == 200:
            break
    except (OSError, http.client.HTTPException):
        request_connection.close()
    time.sleep(.05)
else:
    raise AssertionError('HTTP server did not become ready')

for marker in ('closed', 'failed-closed', 'continue', 'started', 'disconnected', 'cleanup'):
    (state / marker).unlink(missing_ok=True)

status, headers, body = request('/cookies')
assert status == 200 and body == b'ok'
assert [value for key, value in headers if key.lower() == 'set-cookie'] == ['a=1; Path=/', 'b=2; Path=/']
assert request('/head', 'HEAD')[2] == b''
status, headers, body = request('/no-body')
assert status == 204 and body == b''
assert not any(key.lower() == 'content-length' for key, value in headers)
status, headers, body = request('/reset-content')
assert status == 205 and body == b''
assert dict((key.lower(), value) for key, value in headers)['content-length'] == '0'
assert not (state / 'started').exists(), 'HEAD/204 started producer'

connection = http.client.HTTPConnection(url.hostname, url.port, timeout=5)
connection.request('GET', '/stream')
response = connection.getresponse()
assert response.read(5) == b'first'
assert not (state / 'closed').exists(), 'Output buffered until generator completion'
(state / 'continue').write_text('yes')
assert response.read() == b'second'
connection.close()
request_count += 1
wait_file('closed')

status, headers, body = request('/failure')
assert status == 200 and body == b'first', (status, body)
wait_file('failed-closed')

connection = http.client.HTTPConnection(url.hostname, url.port, timeout=5)
connection.request('GET', '/disconnect')
response = connection.getresponse()
assert response.read(5) == b'first'
response.close()
connection.close()
request_count += 1
wait_file('disconnected')
assert int((state / 'disconnected').read_text()) < 1000, 'Producer exhausted instead of stopping on disconnect'

assert request('/echo', 'POST', b'raw body', {'Content-Type': 'text/plain'})[2] == b'raw body'
assert request('/echo', 'POST', b'x' * 3000)[0] == 413
boundary = 'php-hono-runtime-boundary'


def multipart(content):
    return (f'--{boundary}\r\nContent-Disposition: form-data; name="field"\r\n\r\nvalue\r\n'
            f'--{boundary}\r\nContent-Disposition: form-data; name="file"; filename="hello.txt"\r\n'
            'Content-Type: text/plain\r\n\r\n').encode() + content + f'\r\n--{boundary}--\r\n'.encode()


status, headers, body = request('/upload', 'POST', multipart(b'uploaded'), {'Content-Type': f'multipart/form-data; boundary={boundary}'})
assert status == 200, (status, body)
assert json.loads(body) == {'parsed': True, 'fields': {'field': 'value'}, 'name': 'hello.txt', 'content': 'uploaded'}
assert request('/upload', 'POST', multipart(b'x' * 1100), {'Content-Type': f'multipart/form-data; boundary={boundary}'})[0] == 413

boots = set()
counts = []
memories = []
for number in range(probes):
    user = f'user-{number}'
    tenant = f'tenant-{number % 2}'
    status, headers, body = request(f'/probe?value={number}', headers={'X-User': user, 'X-Tenant': tenant, 'Cookie': f'user={user}'})
    headers = {key.lower(): value for key, value in headers}
    assert status == 200, (status, body)
    assert json.loads(body) == {'user': user, 'previous': None, 'cookie': {'user': user},
                                'tenant': tenant, 'touches': 1, 'validated': {'value': str(number)},
                                'greeting': f'hello {user}'}
    assert headers['x-test-retained'] == '0', 'Context, identity or scope retained from an earlier request'
    assert headers['x-test-scope-closed'] == 'yes', 'Successful request did not close its scope'
    assert 'x-response-time' in headers, 'Third-party PSR-15 middleware was not executed'
    boots.add(headers['x-test-boot'])
    counts.append(int(headers['x-test-request']))
    memories.append(int(headers['x-test-memory']))
    denied_status, denied_headers, _ = request('/probe?value=anonymous')
    assert denied_status == 401, 'Missing PSR identity reused an earlier extension'
    assert dict((key.lower(), value) for key, value in denied_headers)['x-test-scope-closed'] == 'yes'
    invalid_status, invalid_headers, _ = request('/probe', headers={'X-User': user, 'X-Tenant': tenant})
    assert invalid_status == 422, 'Missing query reused earlier validated data'
    assert dict((key.lower(), value) for key, value in invalid_headers)['x-test-scope-closed'] == 'yes'
    if number % 3 == 0:
        failed_status, failed_headers, _ = request('/error', headers={'X-User': user, 'X-Tenant': tenant})
        assert failed_status == 500
        assert dict((key.lower(), value) for key, value in failed_headers)['x-test-scope-closed'] == 'yes'
    elif number % 3 == 1:
        assert request('/deny')[0] == 401

if args.worker:
    if args.single_boot:
        assert len(boots) == 1, 'A restart masked the single-worker soak test'
    else:
        assert len(boots) > 1, 'Worker did not restart after its request limit'
    assert max(counts) > 1, 'Application booted again for every request'
    stable_memories = memories[10:] or memories
    assert max(stable_memories) - min(stable_memories) < 1024 * 1024, (
        f'Retained memory grew by more than 1 MiB: first={memories[0]}, last={memories[-1]}, '
        f'min={min(stable_memories)}, max={max(stable_memories)}, boots={len(boots)}, '
        f'samples={[memories[i] for i in range(0, len(memories), max(1, len(memories) // 10))]}'
    )
    wait_file('cleanup')
print(json.dumps({'url': args.url, 'worker': args.worker, 'probes': len(counts), 'boots': len(boots),
                  'http_requests': request_count, 'single_boot': args.single_boot,
                  'max_requests': int(os.environ.get('PHP_HONO_TEST_MAX_REQUESTS', '20')) if args.worker else None,
                  'memory_min': min(memories), 'memory_max': max(memories),
                  'memory_first': memories[0], 'memory_last': memories[-1], 'retained_objects': 0,
                  'typed_scopes': True, 'psr_identity_mapper': True, 'validation_isolation': True,
                  'status': 'passed'}))
