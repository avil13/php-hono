# Third-party notices

php-hono is an independent PHP implementation inspired by Hono; it is not an official port.

The compatibility baseline is Hono 4.13.16, commit c91b60a251186fb024557c6d55a11d8e388f179c. Fixture scenarios are original small applications executed against that upstream source. Its behavioral baseline and any derived material are attributed below. The full JavaScript source is not distributed in this package.

## Hono

Repository: https://github.com/honojs/hono

```text
MIT License

Copyright (c) 2021 - present, Yusuke Wada and Hono contributors

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
```

## Composer dependencies

Runtime dependencies (Nyholm PSR-7/PSR-7 Server and PHP-FIG PSR interfaces) are installed separately by Composer and retain their own MIT licenses in vendor/. Exact versions and license metadata are recorded in composer.lock. Dev tools and middleware fixtures are not runtime dependencies of consumers.
