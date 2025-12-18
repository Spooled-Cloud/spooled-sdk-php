# Publishing to Packagist

This guide explains how to set up automatic publishing of the Spooled PHP SDK to [Packagist](https://packagist.org).

## Initial Setup (One-Time)

### 1. Register on Packagist

1. Go to [packagist.org](https://packagist.org)
2. Click **"Login"** or **"Register"**
3. Sign up using your GitHub account (recommended) or email

### 2. Submit the Package

1. Log in to Packagist
2. Click **"Submit"** in the top navigation
3. Enter the repository URL:
   ```
   https://github.com/spooled-cloud/spooled-sdk-php
   ```
4. Click **"Check"** - Packagist will validate the `composer.json`
5. Click **"Submit"** to register the package

The package will be available at:
```
https://packagist.org/packages/spooled-cloud/spooled
```

### 3. Set Up Auto-Update (GitHub Webhook)

#### Option A: GitHub Integration (Recommended)

1. In Packagist, go to your profile → **"Settings"**
2. Click **"Show API Token"** and copy it
3. In your GitHub repository, go to **Settings** → **Secrets and variables** → **Actions**
4. Add these secrets/variables:
   - **Variable** `PACKAGIST_USERNAME`: Your Packagist username
   - **Secret** `PACKAGIST_TOKEN`: Your Packagist API token

The GitHub Actions workflow will automatically notify Packagist on each release.

#### Option B: GitHub Webhook (Alternative)

1. In Packagist, go to your package page
2. Click **"Settings"**
3. Copy the **"Update Package"** webhook URL
4. In GitHub, go to your repository → **Settings** → **Webhooks**
5. Click **"Add webhook"**
6. Configure:
   - **Payload URL**: The Packagist webhook URL
   - **Content type**: `application/json`
   - **Events**: Select "Releases" and "Pushes"
7. Click **"Add webhook"**

## Release Process

### Creating a New Release

1. **Update version references** (if any) in the code

2. **Create and push a version tag**:
   ```bash
   # For a stable release
   git tag v1.0.0
   git push origin v1.0.0
   
   # For a pre-release
   git tag v1.1.0-beta.1
   git push origin v1.1.0-beta.1
   ```

3. **GitHub Actions will automatically**:
   - Run all tests
   - Create a GitHub Release with changelog
   - Notify Packagist to update the package

### Version Numbering

Follow [Semantic Versioning](https://semver.org/):

- **MAJOR** (1.0.0 → 2.0.0): Breaking changes
- **MINOR** (1.0.0 → 1.1.0): New features, backwards compatible
- **PATCH** (1.0.0 → 1.0.1): Bug fixes, backwards compatible

Pre-release versions:
- `v1.0.0-alpha.1` - Early development
- `v1.0.0-beta.1` - Feature complete, testing
- `v1.0.0-rc.1` - Release candidate

### Manual Release (Emergency)

If GitHub Actions fails, you can trigger a Packagist update manually:

```bash
# Using curl
curl -XPOST -H'content-type:application/json' \
  'https://packagist.org/api/update-package?username=YOUR_USERNAME&apiToken=YOUR_TOKEN' \
  -d'{"repository":{"url":"https://github.com/spooled-cloud/spooled-sdk-php"}}'
```

Or go to your package page on Packagist and click **"Update"**.

## Verification

After releasing:

1. **Check Packagist**: Visit [packagist.org/packages/spooled-cloud/spooled](https://packagist.org/packages/spooled-cloud/spooled)
2. **Verify the version**: The new version should appear within a few minutes
3. **Test installation**:
   ```bash
   composer require spooled-cloud/spooled:^1.0
   ```

## Troubleshooting

### Package not updating on Packagist

1. Check GitHub Actions logs for errors
2. Verify the webhook is configured correctly
3. Manually trigger an update on Packagist

### composer.json validation errors

Packagist validates your `composer.json`. Common issues:

- Invalid JSON syntax
- Missing required fields (`name`, `description`, `license`)
- Invalid package name format (must be `vendor/package`)

Validate locally:
```bash
composer validate
```

### Tag not triggering release

Ensure your tag matches the pattern `v*.*.*`:
```bash
# Correct
git tag v1.0.0
git tag v2.1.3-beta.1

# Wrong (won't trigger)
git tag 1.0.0
git tag release-1.0.0
```

## Links

- [Packagist Documentation](https://packagist.org/about)
- [Composer Versioning](https://getcomposer.org/doc/articles/versions.md)
- [Semantic Versioning](https://semver.org/)
- [GitHub Actions Documentation](https://docs.github.com/en/actions)

