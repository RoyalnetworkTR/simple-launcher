package com.athenastudios.athenacore.util;

import com.google.gson.JsonObject;
import com.google.gson.JsonParser;
import net.minecraft.client.Minecraft;

import java.io.File;
import java.nio.charset.StandardCharsets;
import java.nio.file.Files;

/**
 * Reads the launcher-written config/athena_session.json from the game directory.
 * Format: { "join_token": "...", "username": "...", "uuid": "...", "expires_at": 123 }
 */
public final class SessionFile {
    private SessionFile() {
    }

    public static String readToken() {
        try {
            File dir = Minecraft.getMinecraft().mcDataDir;
            File f = new File(dir, "config/athena_session.json");
            if (!f.isFile()) {
                return null;
            }
            String content = new String(Files.readAllBytes(f.toPath()), StandardCharsets.UTF_8);
            JsonObject o = new JsonParser().parse(content).getAsJsonObject();
            return (o.has("join_token") && !o.get("join_token").isJsonNull())
                    ? o.get("join_token").getAsString() : null;
        } catch (Throwable t) {
            return null;
        }
    }
}
