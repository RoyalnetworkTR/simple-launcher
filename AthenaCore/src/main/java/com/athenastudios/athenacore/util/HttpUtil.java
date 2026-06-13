package com.athenastudios.athenacore.util;

import com.google.gson.JsonObject;
import com.google.gson.JsonParser;

import java.io.BufferedReader;
import java.io.IOException;
import java.io.InputStream;
import java.io.InputStreamReader;
import java.io.OutputStream;
import java.net.HttpURLConnection;
import java.net.URL;
import java.nio.charset.StandardCharsets;

/** Minimal JSON-over-HTTP helper (uses Minecraft's bundled Gson). */
public final class HttpUtil {
    private HttpUtil() {
    }

    public static JsonObject postJson(String urlStr, JsonObject body, String serverKey) throws IOException {
        HttpURLConnection c = (HttpURLConnection) new URL(urlStr).openConnection();
        c.setRequestMethod("POST");
        c.setConnectTimeout(6000);
        c.setReadTimeout(8000);
        c.setRequestProperty("Content-Type", "application/json");
        c.setRequestProperty("Accept", "application/json");
        c.setRequestProperty("User-Agent", "AthenaCore/1.0");
        if (serverKey != null && !serverKey.isEmpty()) {
            c.setRequestProperty("X-Athena-Server-Key", serverKey);
        }
        c.setDoOutput(true);
        byte[] out = body.toString().getBytes(StandardCharsets.UTF_8);
        try (OutputStream os = c.getOutputStream()) {
            os.write(out);
        }
        return readJson(c);
    }

    public static JsonObject getJson(String urlStr, String serverKey) throws IOException {
        HttpURLConnection c = (HttpURLConnection) new URL(urlStr).openConnection();
        c.setRequestMethod("GET");
        c.setConnectTimeout(6000);
        c.setReadTimeout(8000);
        c.setRequestProperty("Accept", "application/json");
        c.setRequestProperty("User-Agent", "AthenaCore/1.0");
        if (serverKey != null && !serverKey.isEmpty()) {
            c.setRequestProperty("X-Athena-Server-Key", serverKey);
        }
        return readJson(c);
    }

    private static JsonObject readJson(HttpURLConnection c) throws IOException {
        int code = c.getResponseCode();
        InputStream is = (code >= 200 && code < 300) ? c.getInputStream() : c.getErrorStream();
        if (is == null) {
            return null;
        }
        StringBuilder sb = new StringBuilder();
        try (BufferedReader r = new BufferedReader(new InputStreamReader(is, StandardCharsets.UTF_8))) {
            String line;
            while ((line = r.readLine()) != null) {
                sb.append(line);
            }
        } finally {
            c.disconnect();
        }
        try {
            return new JsonParser().parse(sb.toString()).getAsJsonObject();
        } catch (Throwable t) {
            return null;
        }
    }
}
