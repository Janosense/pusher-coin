# Google Sign-In Authentication Setup

This document explains how to configure and use the Google Sign-In authentication endpoint for your WordPress site.

## Overview

The Google Sign-In authentication endpoint allows users to authenticate using their Google account. It verifies the Google ID token, creates or authenticates the user, and returns a JWT token for subsequent API requests.

## Endpoint Details

- **URL**: `/wp-json/pc/v1/google-auth/authentication`
- **Method**: `POST`
- **Content-Type**: `application/json`

## Configuration

### 1. Google Client ID

Add your Google Client ID to your `wp-config.php` file:

```php
define('GOOGLE_CLIENT_ID', 'your-google-client-id.apps.googleusercontent.com');
```

Alternatively, you can store it in WordPress options:

```php
update_option('google_client_id', 'your-google-client-id.apps.googleusercontent.com');
```

### 2. JWT Secret Key

Ensure your JWT secret key is configured in `wp-config.php`:

```php
define('JWT_AUTH_SECRET_KEY', 'your-unique-secret-key-here');
```

Generate a secure secret key using:
```bash
openssl rand -base64 32
```

### 3. Player Role

The endpoint creates new users with the "player" role. Ensure this role exists in your WordPress installation. If it doesn't exist, you can create it:

```php
add_role('player', 'Player', array(
    'read' => true,
    'edit_posts' => false,
    'delete_posts' => false,
));
```

## How to Obtain Google Client ID

1. Go to [Google Cloud Console](https://console.cloud.google.com/)
2. Create a new project or select an existing one
3. Enable the **Google+ API** or **Google Identity** service
4. Go to **Credentials** > **Create Credentials** > **OAuth 2.0 Client ID**
5. Configure the OAuth consent screen if prompted
6. Select **Web application** as the application type
7. Add authorized JavaScript origins (e.g., `http://localhost`, `https://yourdomain.com`)
8. Add authorized redirect URIs if needed
9. Copy the **Client ID**

## Request Format

### Request Body

```json
{
  "id_token": "eyJhbGciOiJSUzI1NiIsImtpZCI6IjY4ZGMwMD..."
}
```

### Parameters

- `id_token` (string, required): The Google ID token obtained from Google Sign-In on the client side

## Response Format

### Success Response (200 OK)

```json
{
  "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
  "user_id": 123,
  "user_email": "user@example.com",
  "user_nicename": "user",
  "user_display_name": "John Doe"
}
```

### Error Responses

#### Missing ID Token (400 Bad Request)
```json
{
  "code": "missing_id_token",
  "message": "Google ID token is required.",
  "data": {
    "status": 400
  }
}
```

#### Invalid Token (401 Unauthorized)
```json
{
  "code": "invalid_id_token",
  "message": "Invalid Google ID token.",
  "data": {
    "status": 401
  }
}
```

#### Email Not Verified (403 Forbidden)
```json
{
  "code": "email_not_verified",
  "message": "Email address is not verified by Google.",
  "data": {
    "status": 403
  }
}
```

#### Google Not Configured (500 Internal Server Error)
```json
{
  "code": "google_not_configured",
  "message": "Google authentication is not properly configured. Please contact the administrator.",
  "data": {
    "status": 500
  }
}
```

## Client-Side Integration Example

### JavaScript (with Google Sign-In SDK)

```javascript
// Load Google Sign-In SDK
<script src="https://accounts.google.com/gsi/client" async defer></script>

// Initialize Google Sign-In
google.accounts.id.initialize({
  client_id: 'your-google-client-id.apps.googleusercontent.com',
  callback: handleCredentialResponse
});

// Render the sign-in button
google.accounts.id.renderButton(
  document.getElementById('google-signin-button'),
  { theme: 'outline', size: 'large' }
);

// Handle the credential response
async function handleCredentialResponse(response) {
  const idToken = response.credential;

  try {
    const result = await fetch('/wp-json/api/v1/google-auth', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify({
        id_token: idToken
      })
    });

    const data = await result.json();

    if (result.ok) {
      // Store JWT token for future API requests
      localStorage.setItem('jwt_token', data.token);
      console.log('Authenticated successfully:', data);

      // Use the token for subsequent API requests
      // Authorization: Bearer {data.token}
    } else {
      console.error('Authentication failed:', data);
    }
  } catch (error) {
    console.error('Error during authentication:', error);
  }
}
```

### React Example

```jsx
import { GoogleLogin } from '@react-oauth/google';

function LoginComponent() {
  const handleSuccess = async (credentialResponse) => {
    try {
      const response = await fetch('/wp-json/api/v1/google-auth', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          id_token: credentialResponse.credential
        })
      });

      const data = await response.json();

      if (response.ok) {
        localStorage.setItem('jwt_token', data.token);
        // Handle successful login
      }
    } catch (error) {
      console.error('Authentication error:', error);
    }
  };

  return (
    <GoogleLogin
      onSuccess={handleSuccess}
      onError={() => console.log('Login Failed')}
    />
  );
}
```

## Using the JWT Token

After successful authentication, use the returned JWT token for authenticated API requests:

```javascript
fetch('/wp-json/wp/v2/posts', {
  method: 'GET',
  headers: {
    'Authorization': `Bearer ${jwtToken}`,
    'Content-Type': 'application/json'
  }
});
```

## Features

1. **Token Verification**: Validates Google ID tokens using the official Google API client library
2. **Email Verification Check**: Ensures the user's email is verified by Google
3. **User Management**:
   - Checks if user exists by email
   - Creates new user if doesn't exist
   - Sets role to "Player" for new users
   - Stores Google ID in user meta
4. **JWT Token Generation**: Uses the existing JWT plugin to generate tokens
5. **Security**:
   - Validates all inputs
   - Uses WordPress sanitization functions
   - Checks email verification status
   - Proper error handling

## Troubleshooting

### "Google authentication is not properly configured"
- Ensure `GOOGLE_CLIENT_ID` is defined in `wp-config.php` or set in WordPress options

### "JWT is not configured properly"
- Ensure `JWT_AUTH_SECRET_KEY` is defined in `wp-config.php`

### "Invalid Google ID token"
- Verify the token is fresh (tokens expire after 1 hour)
- Ensure the Client ID matches on both client and server
- Check that the token is being sent correctly in the request

### CORS Issues
If you're calling from a different domain, you may need to enable CORS in your `wp-config.php`:

```php
define('JWT_AUTH_CORS_ENABLE', true);
```

## User Meta Data

For users authenticated via Google, the following meta data is stored:

- `google_id`: The user's unique Google identifier (stored in user meta)

## References

- [Google Sign-In Documentation](https://developers.google.com/identity/sign-in/web/backend-auth)
- [Google API PHP Client](https://github.com/googleapis/google-api-php-client)
- [JWT Authentication for WP REST API](https://wordpress.org/plugins/jwt-authentication-for-wp-rest-api/)
