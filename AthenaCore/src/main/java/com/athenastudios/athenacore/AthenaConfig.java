package com.athenastudios.athenacore;

import net.minecraftforge.common.config.Configuration;

import java.io.File;

/**
 * Mod configuration (config/athenacore.cfg). The dedicated server's secret key
 * is best provided via the ATHENA_SERVER_KEY environment variable so it is not
 * stored inside a world/config file.
 */
public final class AthenaConfig {
    public static String backendUrl = "https://oyna.athenastudios.com.tr";
    public static String serverKey = "";
    public static int graceSeconds = 12;

    private AthenaConfig() {
    }

    public static void load(File file) {
        Configuration cfg = new Configuration(file);
        try {
            cfg.load();
            backendUrl = cfg.getString("backendUrl", Configuration.CATEGORY_GENERAL, backendUrl,
                    "Athena backend base URL (no trailing slash).");
            serverKey = cfg.getString("serverKey", Configuration.CATEGORY_GENERAL, serverKey,
                    "Dedicated-server only: X-Athena-Server-Key shared with the backend. Keep secret. "
                            + "The ATHENA_SERVER_KEY environment variable overrides this.");
            graceSeconds = cfg.getInt("graceSeconds", Configuration.CATEGORY_GENERAL, graceSeconds, 3, 120,
                    "Seconds a player has to present a valid join token before being kicked.");
        } catch (Exception e) {
            if (AthenaCore.LOGGER != null) {
                AthenaCore.LOGGER.warn("[AthenaCore] config load failed, using defaults", e);
            }
        } finally {
            if (cfg.hasChanged()) {
                cfg.save();
            }
        }

        String envKey = System.getenv("ATHENA_SERVER_KEY");
        if (envKey != null && !envKey.isEmpty()) {
            serverKey = envKey;
        }
        // Normalize: strip trailing slash from backendUrl.
        if (backendUrl.endsWith("/")) {
            backendUrl = backendUrl.substring(0, backendUrl.length() - 1);
        }
    }
}
