import java.io.*;
class D { Object r(InputStream s) throws Exception { return new ObjectInputStream(s).readObject(); } }
