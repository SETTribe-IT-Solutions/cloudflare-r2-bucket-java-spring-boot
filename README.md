# Comprehensive Guide: Implementing Cloudflare R2 File Uploads in Spring Boot

## 1. Introduction

This documentation provides a step-by-step guide to integrating **Cloudflare R2** into a Java Spring Boot application. Cloudflare R2 is an S3-compatible object storage service that offers zero egress fees. Because it is S3-compatible, we can use the standard **AWS S3 SDK** to interact with it.

### Objective

To develop a web application that allows users to upload files from a browser and store them securely in a Cloudflare R2 bucket, ultimately generating a publicly accessible URL for the uploaded file.

### Tech Stack

- **Java:** 17 / 21
- **Framework:** Spring Boot 3.x
- **Build Tool:** Maven 3.6+
- **SDK:** AWS SDK for Java (v2)
- **Storage:** Cloudflare R2

---

## 2. Prerequisites & Cloudflare Setup

### Local Requirements

Before writing any code, ensure the following are installed on your machine:

- **Java 17 or higher** — verify with `java -version`
- **Maven 3.6+** — verify with `mvn -version`

### Step 2.1: Create an R2 Bucket

1. Log in to your [Cloudflare Dashboard](https://dash.cloudflare.com/).
2. Navigate to **R2** in the left-hand sidebar.
3. Click **Create Bucket**.
4. Provide a unique bucket name (e.g., `my-app-uploads`) and click **Create bucket**.

### Step 2.2: Enable Public Access (Optional but required for this guide)

To generate a publicly accessible URL for the uploaded files:

1. Go to your newly created bucket's **Settings** tab.
2. Under **Public Access**, click **Allow Access** on the `r2.dev` subdomain, OR connect a custom domain.
3. Note the generated Public URL (e.g., `https://pub-xxxxx.r2.dev`).

### Step 2.3: Generate API Credentials

1. Go back to the main R2 dashboard overview.
2. Click **Manage R2 API Tokens** on the right side.
3. Click **Create API token**.
4. Set permissions to **Object Read & Write**.
5. Click **Create API Token**.
6. **IMPORTANT:** Copy the following values immediately (they will only be shown once):
   - **Access Key ID**
   - **Secret Access Key**
   - **Account ID** (found in the endpoint URL or the main dashboard URL)

---

## 3. Project Setup

Initialize a standard Spring Boot Web project using [Spring Initializr](https://start.spring.io/) or your IDE.

### Step 3.1: Maven Dependencies (`pom.xml`)

Add the required dependencies using the **AWS BOM** (Bill of Materials) to manage SDK versions consistently — this is the recommended approach.

```xml
<dependencyManagement>
    <dependencies>
        <!-- AWS SDK BOM: manages all AWS SDK module versions centrally -->
        <dependency>
            <groupId>software.amazon.awssdk</groupId>
            <artifactId>bom</artifactId>
            <version>2.20.160</version>
            <type>pom</type>
            <scope>import</scope>
        </dependency>
    </dependencies>
</dependencyManagement>

<dependencies>
    <!-- Spring Boot Web Starter -->
    <dependency>
        <groupId>org.springframework.boot</groupId>
        <artifactId>spring-boot-starter-web</artifactId>
    </dependency>

    <!-- AWS SDK v2 for S3 (version managed by BOM above, no <version> needed) -->
    <dependency>
        <groupId>software.amazon.awssdk</groupId>
        <artifactId>s3</artifactId>
    </dependency>
</dependencies>
```

### Step 3.2: Application Properties (`application.properties`)

Configure the Spring Boot multipart file settings and your Cloudflare credentials.

```properties
# File Upload Limits
spring.servlet.multipart.max-file-size=100MB
spring.servlet.multipart.max-request-size=100MB

# Cloudflare R2 Configuration
# Replace these with the values obtained from Step 2
cloudflare.account-id=YOUR_ACCOUNT_ID
cloudflare.access-key=YOUR_ACCESS_KEY
cloudflare.secret-key=YOUR_SECRET_KEY
cloudflare.bucket-name=YOUR_BUCKET_NAME
cloudflare.public-url=https://pub-xxxxx.r2.dev
```

> **⚠️ Security Warning:** Never commit real credentials to version control.
> Add `application.properties` to your `.gitignore` and use environment variables or a secrets manager in production.
> Use the provided `application.properties` only as a template — create a local copy for your actual values.

### Step 3.3: `.gitignore`

Ensure your `.gitignore` includes the following to protect secrets:

```
# Ignore local properties file containing real credentials
src/main/resources/application.properties

# Keep the template in version control
!src/main/resources/application.properties.example
```

---

## 4. Implementation

### Project Structure

```
src/
└── main/
    ├── java/com/r2/upload/
    │   ├── Application.java          # Spring Boot entry point
    │   ├── config/
    │   │   └── R2Config.java         # S3Client Spring Bean
    │   ├── controller/
    │   │   └── UploadController.java # HTTP endpoint
    │   └── service/
    │       └── StorageService.java   # Upload business logic
    └── resources/
        ├── static/
        │   └── index.html            # Upload UI
        └── application.properties    # Configuration
```

### Step 4.1: S3Client Configuration (`R2Config.java`)

Create the `S3Client` **once** as a Spring Bean instead of rebuilding it on every request. This is more efficient and follows Spring best practices.

```java
package com.r2.upload.config;

import org.springframework.beans.factory.annotation.Value;
import org.springframework.context.annotation.Bean;
import org.springframework.context.annotation.Configuration;
import software.amazon.awssdk.auth.credentials.AwsBasicCredentials;
import software.amazon.awssdk.auth.credentials.StaticCredentialsProvider;
import software.amazon.awssdk.regions.Region;
import software.amazon.awssdk.services.s3.S3Client;

import java.net.URI;

@Configuration
public class R2Config {

    @Value("${cloudflare.account-id}")
    private String accountId;

    @Value("${cloudflare.access-key}")
    private String accessKey;

    @Value("${cloudflare.secret-key}")
    private String secretKey;

    @Bean
    public S3Client s3Client() {
        return S3Client.builder()
                .region(Region.of("auto")) // R2 does not use standard AWS regions
                .endpointOverride(URI.create("https://" + accountId + ".r2.cloudflarestorage.com"))
                .credentialsProvider(StaticCredentialsProvider.create(
                        AwsBasicCredentials.create(accessKey, secretKey)
                ))
                .build();
    }
}
```

### Step 4.2: The Storage Service (`StorageService.java`)

Move the upload logic into a dedicated `@Service` class to follow standard MVC architecture and keep the controller lean.

```java
package com.r2.upload.service;

import org.springframework.beans.factory.annotation.Value;
import org.springframework.stereotype.Service;
import org.springframework.web.multipart.MultipartFile;
import software.amazon.awssdk.core.sync.RequestBody;
import software.amazon.awssdk.services.s3.S3Client;
import software.amazon.awssdk.services.s3.model.PutObjectRequest;

import java.io.IOException;
import java.util.UUID;

@Service
public class StorageService {

    private final S3Client s3Client;

    @Value("${cloudflare.bucket-name}")
    private String bucketName;

    @Value("${cloudflare.public-url}")
    private String publicUrl;

    public StorageService(S3Client s3Client) {
        this.s3Client = s3Client;
    }

    public String uploadFile(MultipartFile file) throws IOException {
        // Sanitize filename: prepend UUID to avoid collisions and path traversal attacks
        String originalFilename = file.getOriginalFilename() != null
                ? file.getOriginalFilename().replaceAll("[^a-zA-Z0-9._-]", "_")
                : "upload";
        String safeFileName = UUID.randomUUID() + "_" + originalFilename;

        // Build the put request with Content-Type so files serve correctly from public URL
        PutObjectRequest putRequest = PutObjectRequest.builder()
                .bucket(bucketName)
                .key(safeFileName)
                .contentType(file.getContentType())  // ensures browser previews files correctly
                .build();

        s3Client.putObject(putRequest, RequestBody.fromBytes(file.getBytes()));

        return publicUrl + "/" + safeFileName;
    }
}
```

### Step 4.3: The Upload Controller (`UploadController.java`)

The controller now only handles HTTP concerns and delegates to the service layer.

```java
package com.r2.upload.controller;

import com.r2.upload.service.StorageService;
import org.springframework.http.ResponseEntity;
import org.springframework.web.bind.annotation.*;
import org.springframework.web.multipart.MultipartFile;

import java.io.IOException;

@RestController
public class UploadController {

    private final StorageService storageService;

    public UploadController(StorageService storageService) {
        this.storageService = storageService;
    }

    @PostMapping("/upload")
    public ResponseEntity<String> uploadFile(@RequestParam("file") MultipartFile file) {
        try {
            String fileUrl = storageService.uploadFile(file);
            return ResponseEntity.ok(
                "File uploaded successfully.<br><br>"
                + "<a href='" + fileUrl + "' target='_blank'>" + fileUrl + "</a>"
            );
        } catch (IOException e) {
            return ResponseEntity.internalServerError()
                    .body("Upload failed: " + e.getMessage());
        }
    }
}
```

### Step 4.4: The Frontend Form (`src/main/resources/static/index.html`)

Create a simple HTML form to test the upload functionality.

```html
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Upload File to Cloudflare R2</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; }
        .upload-container { border: 1px solid #ccc; padding: 20px; max-width: 400px; border-radius: 8px; }
        button { margin-top: 10px; padding: 8px 15px; cursor: pointer; }
    </style>
</head>
<body>
    <div class="upload-container">
        <h2>Upload File to Cloudflare R2</h2>
        <form action="/upload" method="post" enctype="multipart/form-data">
            <input type="file" name="file" required>
            <br>
            <button type="submit">Upload</button>
        </form>
    </div>
</body>
</html>
```

### Step 4.5: Main Application Class (`Application.java`)

Ensure your standard Spring Boot main class is present.

```java
package com.r2.upload;

import org.springframework.boot.SpringApplication;
import org.springframework.boot.autoconfigure.SpringBootApplication;

@SpringBootApplication
public class Application {
    public static void main(String[] args) {
        SpringApplication.run(Application.class, args);
    }
}
```

---

## 5. Running and Testing the Application

1. **Start the application** using Maven:

   ```bash
   mvn spring-boot:run
   ```

2. **Access the UI:** Open your browser and navigate to `http://localhost:8080`.

3. **Upload a file:** Choose a file from your computer and click the **Upload** button.

4. **Verify:** Upon successful upload, you will receive a success message with a clickable hyperlink. Clicking the link should open the file directly from your Cloudflare R2 bucket.

---

## 6. Troubleshooting

| Error | Likely Cause | Fix |
|-------|-------------|-----|
| `403 Forbidden` | Wrong credentials or insufficient permissions | Re-check Access Key, Secret Key, and ensure the API token has **Object Read & Write** permissions |
| `NoSuchBucket` | Bucket name typo | Verify `cloudflare.bucket-name` matches the exact bucket name in the R2 dashboard |
| `Connection refused` / `UnknownHost` | Wrong Account ID in the endpoint URL | Verify `cloudflare.account-id` from your Cloudflare dashboard URL |
| File opens as download instead of preview | Missing `Content-Type` header | Ensure `contentType` is set in `PutObjectRequest` (already handled in Step 4.2) |
| `MaxUploadSizeExceededException` | File exceeds configured limit | Increase `spring.servlet.multipart.max-file-size` in `application.properties` |

---

## 7. Best Practices & Future Enhancements

When taking this into production, consider the following improvements:

1. **Secrets Management:** Use environment variables, Spring Cloud Config, or a secrets manager (e.g., AWS Secrets Manager, HashiCorp Vault) instead of hardcoding credentials in `application.properties`.

2. **File Type Validation:** Validate `file.getContentType()` against an allowlist of accepted MIME types to prevent malicious file uploads.

3. **File Size Validation:** Add programmatic size checks in the service layer in addition to the multipart limits.

4. **Error Handling:** Implement a `@ControllerAdvice` class to handle `MaxUploadSizeExceededException` or S3 connection errors globally and return structured error responses.

5. **Security:** Implement authentication (e.g., Spring Security) to prevent unauthorized uploads to your bucket.

6. **Async Uploads:** For large files, consider making the upload asynchronous using `@Async` to avoid blocking the HTTP thread.