import com.fasterxml.jackson.databind.ObjectMapper;
class D { Object r(String s) throws Exception { return new ObjectMapper().readValue(s, Map.class); } }
