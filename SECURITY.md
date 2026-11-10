# Security policy

**English** | [Русский](SECURITY.ru.md)

Before the 1.0 release, fixes are accepted on the current development branch. After release, the latest patch version of the 1.x branch is supported. PHP support starts at 8.5.

Do not publish exploits or secrets in a public issue. Use **Security → Report a vulnerability** in the repository if private vulnerability reporting is enabled. If a private channel has not been configured, open an issue asking for a private contact without including vulnerability details.

To reproduce a report, provide the version/commit, PHP and runtime versions, minimal code, and a request with sensitive data removed. Describe the expected and actual behavior. The fix timeline is determined after assessment; there is no guaranteed SLA.

Deployment boundary: trusted reverse proxies must be configured by the application/server. Forwarded headers do not automatically make the scheme or IP trusted. Authentication requires HTTPS. Raw multipart parsing, JWT, signed cookies, and WebSocket are not included in this version. Limitations and safe middleware ordering are described in the [documentation](docs/en/overview.md).
