using System;
using System.Collections.Generic;
using System.IO;
using System.Text;

namespace OfflineMinecraftLauncher.Services;

/// <summary>
/// Writes/merges the Athena server into Minecraft's multiplayer list
/// (<c>servers.dat</c> — uncompressed, big-endian NBT) so the server appears
/// automatically in the in-game "Multiplayer" screen. Self-contained minimal NBT
/// codec; no external dependency. Existing entries are preserved and the Athena
/// entry is de-duplicated by IP.
/// </summary>
public static class ServersDat
{
    public static void WriteOrMerge(string gameDir, string name, string ip)
    {
        if (string.IsNullOrWhiteSpace(gameDir) || string.IsNullOrWhiteSpace(ip))
            return;
        try
        {
            string path = Path.Combine(gameDir, "servers.dat");

            NbtCompound root;
            try
            {
                if (File.Exists(path))
                {
                    using var fs = File.OpenRead(path);
                    root = NbtIo.ReadRoot(fs);
                }
                else
                {
                    root = new NbtCompound();
                }
            }
            catch
            {
                root = new NbtCompound(); // corrupt/unreadable -> rebuild cleanly
            }

            // Find or (re)create the "servers" list of compounds.
            var servers = root.Get("servers") as NbtList;
            if (servers == null || servers.ElementType != 10)
                servers = new NbtList { ElementType = 10 };

            bool exists = false;
            foreach (var item in servers.Items)
            {
                if (item is NbtCompound c && c.Get("ip") is string existingIp
                    && string.Equals(existingIp, ip, StringComparison.OrdinalIgnoreCase))
                {
                    c.Set("name", name); // keep our display name fresh
                    exists = true;
                    break;
                }
            }
            if (!exists)
            {
                var entry = new NbtCompound();
                entry.Set("name", name);
                entry.Set("ip", ip);
                servers.Items.Insert(0, entry); // top of the list
            }

            root.Set("servers", servers);

            string tmp = path + ".tmp";
            using (var fs = File.Create(tmp))
                NbtIo.WriteRoot(fs, root);
            if (File.Exists(path)) File.Delete(path);
            File.Move(tmp, path);
        }
        catch
        {
            // Never block the launch over the multiplayer list.
        }
    }
}

// ---------------------------------------------------------------------------
// Minimal NBT object model
// ---------------------------------------------------------------------------

internal sealed class NbtCompound
{
    public readonly List<KeyValuePair<string, object>> Items = new();

    public object? Get(string name)
    {
        foreach (var kv in Items)
            if (kv.Key == name) return kv.Value;
        return null;
    }

    public void Set(string name, object value)
    {
        for (int i = 0; i < Items.Count; i++)
        {
            if (Items[i].Key == name) { Items[i] = new KeyValuePair<string, object>(name, value); return; }
        }
        Items.Add(new KeyValuePair<string, object>(name, value));
    }
}

internal sealed class NbtList
{
    public int ElementType;             // NBT tag id of the elements
    public readonly List<object> Items = new();
}

// ---------------------------------------------------------------------------
// NBT reader / writer (big-endian, uncompressed)
// ---------------------------------------------------------------------------

internal static class NbtIo
{
    public static NbtCompound ReadRoot(Stream s)
    {
        int type = s.ReadByte();
        if (type != 10) throw new InvalidDataException("NBT root is not a compound");
        ReadString(s);                  // root name (ignored)
        return ReadCompound(s);
    }

    public static void WriteRoot(Stream s, NbtCompound root)
    {
        s.WriteByte(10);
        WriteString(s, "");             // root name
        WriteCompound(s, root);
    }

    private static NbtCompound ReadCompound(Stream s)
    {
        var c = new NbtCompound();
        while (true)
        {
            int type = s.ReadByte();
            if (type <= 0) break;       // TAG_End (0) or EOF (-1)
            string name = ReadString(s);
            c.Items.Add(new KeyValuePair<string, object>(name, ReadPayload(s, type)));
        }
        return c;
    }

    private static void WriteCompound(Stream s, NbtCompound c)
    {
        foreach (var kv in c.Items)
        {
            int type = TypeOf(kv.Value);
            s.WriteByte((byte)type);
            WriteString(s, kv.Key);
            WritePayload(s, type, kv.Value);
        }
        s.WriteByte(0);                 // TAG_End
    }

    private static object ReadPayload(Stream s, int type)
    {
        switch (type)
        {
            case 1:  return (byte)ReadU8(s);
            case 2:  return ReadI16(s);
            case 3:  return ReadI32(s);
            case 4:  return ReadI64(s);
            case 5:  return BitConverter.Int32BitsToSingle(ReadI32(s));
            case 6:  return BitConverter.Int64BitsToDouble(ReadI64(s));
            case 7:  return ReadBytes(s, ReadI32(s));
            case 8:  return ReadString(s);
            case 9:
            {
                int et = ReadU8(s);
                int len = ReadI32(s);
                var list = new NbtList { ElementType = et };
                for (int i = 0; i < len; i++) list.Items.Add(ReadPayload(s, et));
                return list;
            }
            case 10: return ReadCompound(s);
            case 11:
            {
                int len = ReadI32(s);
                var a = new int[len];
                for (int i = 0; i < len; i++) a[i] = ReadI32(s);
                return a;
            }
            case 12:
            {
                int len = ReadI32(s);
                var a = new long[len];
                for (int i = 0; i < len; i++) a[i] = ReadI64(s);
                return a;
            }
            default: throw new InvalidDataException("Unknown NBT tag " + type);
        }
    }

    private static void WritePayload(Stream s, int type, object v)
    {
        switch (type)
        {
            case 1:  s.WriteByte(Convert.ToByte(v)); break;
            case 2:  WriteI16(s, Convert.ToInt16(v)); break;
            case 3:  WriteI32(s, Convert.ToInt32(v)); break;
            case 4:  WriteI64(s, Convert.ToInt64(v)); break;
            case 5:  WriteI32(s, BitConverter.SingleToInt32Bits(Convert.ToSingle(v))); break;
            case 6:  WriteI64(s, BitConverter.DoubleToInt64Bits(Convert.ToDouble(v))); break;
            case 7:  { var b = (byte[])v; WriteI32(s, b.Length); s.Write(b, 0, b.Length); break; }
            case 8:  WriteString(s, (string)v); break;
            case 9:
            {
                var list = (NbtList)v;
                int et = list.ElementType;
                if (et == 0) et = list.Items.Count > 0 ? TypeOf(list.Items[0]) : 1;
                s.WriteByte((byte)et);
                WriteI32(s, list.Items.Count);
                foreach (var it in list.Items) WritePayload(s, et, it);
                break;
            }
            case 10: WriteCompound(s, (NbtCompound)v); break;
            case 11: { var a = (int[])v;  WriteI32(s, a.Length); foreach (var x in a) WriteI32(s, x); break; }
            case 12: { var a = (long[])v; WriteI32(s, a.Length); foreach (var x in a) WriteI64(s, x); break; }
        }
    }

    private static int TypeOf(object v) => v switch
    {
        byte   => 1,
        sbyte  => 1,
        short  => 2,
        int    => 3,
        long   => 4,
        float  => 5,
        double => 6,
        byte[] => 7,
        string => 8,
        NbtList     => 9,
        NbtCompound => 10,
        int[]  => 11,
        long[] => 12,
        _ => throw new InvalidDataException("Unsupported NBT value type " + (v?.GetType().Name ?? "null"))
    };

    // ---- big-endian primitives ----
    private static int ReadU8(Stream s)  { int b = s.ReadByte(); if (b < 0) throw new EndOfStreamException(); return b; }
    private static int ReadU16(Stream s) => (ReadU8(s) << 8) | ReadU8(s);
    private static short ReadI16(Stream s) => (short)ReadU16(s);
    private static int ReadI32(Stream s) => (ReadU8(s) << 24) | (ReadU8(s) << 16) | (ReadU8(s) << 8) | ReadU8(s);
    private static long ReadI64(Stream s) { long v = 0; for (int i = 0; i < 8; i++) v = (v << 8) | (uint)ReadU8(s); return v; }

    private static void WriteU16(Stream s, int v) { s.WriteByte((byte)(v >> 8)); s.WriteByte((byte)v); }
    private static void WriteI16(Stream s, short v) => WriteU16(s, (ushort)v);
    private static void WriteI32(Stream s, int v) { s.WriteByte((byte)(v >> 24)); s.WriteByte((byte)(v >> 16)); s.WriteByte((byte)(v >> 8)); s.WriteByte((byte)v); }
    private static void WriteI64(Stream s, long v) { for (int i = 7; i >= 0; i--) s.WriteByte((byte)(v >> (i * 8))); }

    private static byte[] ReadBytes(Stream s, int len)
    {
        var b = new byte[len];
        int off = 0;
        while (off < len) { int r = s.Read(b, off, len - off); if (r <= 0) throw new EndOfStreamException(); off += r; }
        return b;
    }

    private static string ReadString(Stream s)
    {
        int len = ReadU16(s);
        if (len == 0) return "";
        return Encoding.UTF8.GetString(ReadBytes(s, len));
    }

    private static void WriteString(Stream s, string str)
    {
        var b = Encoding.UTF8.GetBytes(str ?? "");
        WriteU16(s, b.Length);
        s.Write(b, 0, b.Length);
    }
}
