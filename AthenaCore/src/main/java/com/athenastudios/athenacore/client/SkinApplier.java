package com.athenastudios.athenacore.client;

import com.athenastudios.athenacore.AthenaConfig;
import com.athenastudios.athenacore.AthenaCore;
import com.athenastudios.athenacore.util.HttpUtil;
import com.google.gson.JsonObject;
import com.mojang.authlib.minecraft.MinecraftProfileTexture;
import net.minecraft.client.Minecraft;
import net.minecraft.client.entity.AbstractClientPlayer;
import net.minecraft.client.network.NetHandlerPlayClient;
import net.minecraft.client.network.NetworkPlayerInfo;
import net.minecraft.client.renderer.ImageBufferDownload;
import net.minecraft.client.renderer.texture.ThreadDownloadImageData;
import net.minecraft.client.resources.DefaultPlayerSkin;
import net.minecraft.util.ResourceLocation;

import java.lang.reflect.Field;
import java.lang.reflect.Modifier;
import java.util.Map;
import java.util.Set;
import java.util.UUID;
import java.util.concurrent.ConcurrentHashMap;

/**
 * Fetches each player's custom skin from the backend and injects it into the
 * vanilla skin pipeline. NetworkPlayerInfo fields are located by TYPE (the Map,
 * the boolean and the String), which survives obfuscation without hardcoding SRG
 * names. Everything is best-effort: on any failure the vanilla default skin is
 * used and no exception escapes.
 *
 * NOTE: verify skin rendering once in a real 1.12.2 client; if a field cannot be
 * resolved, skins simply won't apply (auth/ban is unaffected).
 */
public final class SkinApplier {
    private static final Set<UUID> REQUESTED = ConcurrentHashMap.newKeySet();

    private static Field fTextures; // Map<MinecraftProfileTexture.Type, ResourceLocation> playerTextures
    private static Field fLoaded;   // boolean playerTexturesLoaded
    private static Field fSkinType; // String skinType
    private static boolean fieldsResolved = false;

    private SkinApplier() {
    }

    public static void reset() {
        REQUESTED.clear();
    }

    public static void apply(AbstractClientPlayer player) {
        if (player.getGameProfile() == null) {
            return;
        }
        final UUID id = player.getGameProfile().getId();
        if (id == null || !REQUESTED.add(id)) {
            return; // already requested this session
        }
        final String uuid = id.toString();
        new Thread(() -> {
            try {
                JsonObject meta = HttpUtil.getJson(AthenaConfig.backendUrl + "/api/skin/" + uuid + ".json", null);
                if (meta == null || !meta.has("custom") || !meta.get("custom").getAsBoolean()) {
                    return;
                }
                final String model = (meta.has("model") && !meta.get("model").isJsonNull())
                        ? meta.get("model").getAsString() : "default";
                final String url = AthenaConfig.backendUrl + "/api/skin/" + uuid + ".png";
                Minecraft.getMinecraft().addScheduledTask(() -> applyOnMainThread(id, url, model));
            } catch (Throwable t) {
                AthenaCore.LOGGER.warn("[AthenaCore] skin meta fetch failed: " + uuid, t);
            }
        }, "AthenaCore-Skin").start();
    }

    @SuppressWarnings("unchecked")
    private static void applyOnMainThread(UUID id, String url, String model) {
        try {
            NetHandlerPlayClient conn = Minecraft.getMinecraft().getConnection();
            if (conn == null) {
                return;
            }
            NetworkPlayerInfo npi = conn.getPlayerInfo(id);
            if (npi == null) {
                return;
            }

            ResourceLocation loc = new ResourceLocation(
                    AthenaCore.MODID, "skins/" + id.toString().replace("-", ""));
            ResourceLocation fallback = DefaultPlayerSkin.getDefaultSkin(id);
            ThreadDownloadImageData tex =
                    new ThreadDownloadImageData(null, url, fallback, new ImageBufferDownload());
            Minecraft.getMinecraft().getTextureManager().loadTexture(loc, tex);

            resolveFields(npi.getClass());
            if (fTextures != null) {
                Object mapObj = fTextures.get(npi);
                if (mapObj instanceof Map) {
                    ((Map<MinecraftProfileTexture.Type, ResourceLocation>) mapObj)
                            .put(MinecraftProfileTexture.Type.SKIN, loc);
                }
            }
            if (fSkinType != null) {
                fSkinType.set(npi, "slim".equals(model) ? "slim" : "default");
            }
            if (fLoaded != null) {
                fLoaded.setBoolean(npi, true); // stop vanilla from reloading default textures
            }
        } catch (Throwable t) {
            AthenaCore.LOGGER.warn("[AthenaCore] skin apply failed", t);
        }
    }

    private static synchronized void resolveFields(Class<?> cls) {
        if (fieldsResolved) {
            return;
        }
        fieldsResolved = true;
        for (Field f : cls.getDeclaredFields()) {
            if (Modifier.isStatic(f.getModifiers())) {
                continue;
            }
            Class<?> type = f.getType();
            if (fTextures == null && Map.class.isAssignableFrom(type)) {
                f.setAccessible(true);
                fTextures = f;
            } else if (fLoaded == null && type == boolean.class) {
                f.setAccessible(true);
                fLoaded = f;
            } else if (fSkinType == null && type == String.class) {
                f.setAccessible(true);
                fSkinType = f;
            }
        }
    }
}
