using System;
using System.Net.Http;
using System.Threading.Tasks;
using CmlLib.Core;
using CmlLib.Core.ModLoaders.FabricMC;

class Program
{
    static async Task Main(string[] args)
    {
        try
        {
            Console.WriteLine("Testing fabric install...");
            var path = new MinecraftPath(System.IO.Path.Combine(Environment.CurrentDirectory, "test_mc"));
            var fabricInstaller = new FabricInstaller(new HttpClient());
            string version = await fabricInstaller.Install("1.20.1", path);
            Console.WriteLine("Version installed: " + version);
        }
        catch(Exception e)
        {
            Console.WriteLine("ERROR: " + e.ToString());
        }
    }
}
